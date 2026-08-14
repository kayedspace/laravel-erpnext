<?php

namespace Kayedspace\Erpnext\Client;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kayedspace\Erpnext\Connection;
use Kayedspace\Erpnext\Exceptions\DocumentNotFoundException;
use Kayedspace\Erpnext\Exceptions\ErpException;
use Kayedspace\Erpnext\Files\PendingUpload;
use Throwable;

/**
 * HTTP client for an ERPNext / Frappe site.
 *
 * Every method takes its doctype explicitly — there is deliberately no mutable
 * "current doctype" state to forget to set.
 *
 * The connection is supplied as a closure and resolved on **every** request, not
 * captured at construction. That is what makes one long-lived instance safe in a
 * multi-tenant process: credentials follow whatever the resolver reports now.
 */
class ErpClient
{
    /**
     * @param  Closure(): Connection  $connection
     * @param  array<string, string>  $namingFields  doctype => the field its name is derived from
     */
    public function __construct(
        private readonly Closure $connection,
        private readonly array $namingFields = [],
    ) {}

    public function connection(): Connection
    {
        return ($this->connection)();
    }

    /**
     * Scope the client to one doctype. This is the primary entry point.
     */
    public function doctype(string $doctype): Doctype
    {
        return new Doctype($this, $doctype);
    }

    public function query(string $doctype): ErpQuery
    {
        return new ErpQuery($this, $doctype);
    }

    /**
     * @return array<string, mixed>|null Null when the document does not exist.
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function find(string $doctype, string $name): ?array
    {
        $response = $this->send(
            fn (PendingRequest $request, Connection $c): Response => $request->get(
                $c->resourceUrl($doctype).'/'.rawurlencode($name),
            ),
        );

        if ($response->status() === 404) {
            return null;
        }

        $data = $this->responseData($response);
        $this->ensureSuccessful($response, $data);

        return $data['data'] ?? null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function findOrFail(string $doctype, string $name): array
    {
        return $this->find($doctype, $name) ?? throw DocumentNotFoundException::for($doctype, $name);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  string|null  $uniqueBy  Appended to the document name if it is already taken.
     * @return array<string, mixed> The created document.
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function create(string $doctype, array $data, ?string $uniqueBy = null): array
    {
        $data = $this->ensureUniqueName($doctype, $data, null, $uniqueBy);

        $response = $this->send(
            fn (PendingRequest $request, Connection $c): Response => $request->post(
                $c->resourceUrl($doctype),
                $data,
            ),
        );

        $body = $this->responseData($response);
        $this->ensureSuccessful($response, $body);

        return $body['data'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> The updated document.
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function update(string $doctype, string $name, array $data, ?string $uniqueBy = null): array
    {
        $data = $this->ensureUniqueName($doctype, $data, $name, $uniqueBy);

        $response = $this->send(
            fn (PendingRequest $request, Connection $c): Response => $request->put(
                $c->resourceUrl($doctype).'/'.rawurlencode($name),
                $data,
            ),
        );

        $body = $this->responseData($response);
        $this->ensureSuccessful($response, $body);

        return $body['data'];
    }

    /**
     * @throws ConnectionException
     * @throws ErpException
     */
    public function delete(string $doctype, string $name): void
    {
        $response = $this->send(
            fn (PendingRequest $request, Connection $c): Response => $request->delete(
                $c->resourceUrl($doctype).'/'.rawurlencode($name),
            ),
        );

        if (! $response->successful()) {
            $this->ensureSuccessful($response, $this->responseData($response));
        }
    }

    /**
     * Call a whitelisted document method, e.g. a Subscription's `cancel_subscription`.
     * These are not REST resources, so they go through Frappe's run_doc_method endpoint.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function call(string $doctype, string $name, string $method, array $args = []): array
    {
        $response = $this->send(
            fn (PendingRequest $request, Connection $c): Response => $request->asForm()->post(
                $c->methodUrl('run_doc_method'),
                [
                    'dt' => $doctype,
                    'dn' => $name,
                    'method' => $method,
                    'args' => json_encode($args, JSON_THROW_ON_ERROR),
                ],
            ),
        );

        $body = $this->responseData($response);

        if (! $response->successful() || array_key_exists('exception', $body)) {
            $reason = $body['exception'] ?? "HTTP {$response->status()}";

            throw new ErpException(
                "ERPNext {$doctype}.{$method} failed for [{$name}]: ".$this->describe($reason, 300)
            );
        }

        return $body;
    }

    /**
     * Start a file upload.
     */
    public function upload(): PendingUpload
    {
        return new PendingUpload($this);
    }

    /**
     * POST a file to Frappe's upload endpoint.
     *
     * This is the one endpoint that does not answer like the rest of the API: it is an
     * RPC method, so the created File document comes back under `message` rather than
     * `data`, and a chunked upload answers `null` until the final part lands. We never
     * chunk, so a null here means the upload genuinely produced nothing.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed> The created File document.
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function uploadFile(array $form, string $contents, string $filename): array
    {
        $response = $this->send(
            fn (PendingRequest $request, Connection $c): Response => $request
                ->attach('file', $contents, $filename)
                ->post($c->methodUrl('upload_file'), $form),
            json: false,
        );

        $body = $this->responseData($response);

        if (! $response->successful() || array_key_exists('exception', $body)) {
            $reason = $body['exception'] ?? $body['message'] ?? "HTTP {$response->status()}";

            throw new ErpException("ERPNext rejected the upload of [{$filename}]: ".$this->describe($reason, 300));
        }

        $file = $body['message'] ?? null;

        if (! is_array($file)) {
            throw new ErpException("ERPNext returned no File document for the upload of [{$filename}].");
        }

        return $file;
    }

    /**
     * Fetch a file's bytes, authenticated — which is what private files require.
     *
     * A `file_url` is document data, and document data can be written by anyone with
     * desk access. Since this request carries the site's credentials, an absolute URL
     * is only followed when it points at the configured site: otherwise a hostile
     * value in an Attach field would send the Authorization header to a host of the
     * attacker's choosing.
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function downloadFile(string $fileUrl): string
    {
        $connection = $this->connection();
        $url = $this->resolveFileUrl($connection, $fileUrl);

        $response = $this->send(fn (PendingRequest $request): Response => $request->get($url), json: false);

        if (! $response->successful()) {
            throw new ErpException(
                "ERPNext refused to serve [{$fileUrl}]: HTTP {$response->status()}."
            );
        }

        return $response->body();
    }

    /**
     * Run a list request. Public because {@see ErpQuery} is the intended way in.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<int, array<string, mixed>>
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function search(string $doctype, array $parameters): array
    {
        $response = $this->send(
            fn (PendingRequest $request, Connection $c): Response => $request->get(
                $c->resourceUrl($doctype),
                $parameters,
            ),
        );

        $body = $this->responseData($response);
        $this->ensureSuccessful($response, $body);

        $data = $body['data'] ?? null;

        if (! is_array($data)) {
            return [];
        }

        /*
         * A list endpoint returns rows. Keeping only the array members means a site (or
         * a test double) that answers with a single document instead of a list degrades
         * to "no results" rather than handing back scalars the caller will choke on.
         */
        return array_values(array_filter($data, is_array(...)));
    }

    /**
     * Issue a request, and if the site rejects our credentials, re-establish them and
     * try once more.
     *
     * Only stateful authenticators can be re-established, so for token/basic/bearer
     * this is a single attempt and a 401 surfaces immediately. The one retry is the
     * entire budget: a second rejection is a real failure, not a stale session.
     *
     * @param  Closure(PendingRequest, Connection): Response  $send
     *
     * @throws ConnectionException
     */
    private function send(Closure $send, bool $json = true): Response
    {
        $connection = $this->connection();
        $response = $send($this->request($connection, $json), $connection);

        if (! in_array($response->status(), [401, 403], true)) {
            return $response;
        }

        if (! $connection->auth->refresh()) {
            return $response;
        }

        return $send($this->request($connection, $json), $connection);
    }

    /**
     * @param  bool  $json  False for multipart uploads, which set their own content type
     *                      and boundary; forcing application/json there corrupts the body.
     */
    private function request(Connection $connection, bool $json = true): PendingRequest
    {
        $request = Http::connectTimeout($connection->connectTimeout)
            ->timeout($connection->timeout)
            ->withOptions(['verify' => $connection->verifySsl])
            ->withUserAgent($connection->userAgent);

        if ($connection->retries > 1) {
            /*
             * Frappe rate-limits per site and answers 429, and a bench restart shows up
             * as a 502/503 for a second or two. Both are worth waiting out; a 4xx that
             * is neither is our fault and retrying it only multiplies the damage.
             *
             * Authentication failures are excluded because send() has its own, better
             * answer for those: re-establish the session and try once.
             */
            $request = $request->retry(
                $connection->retries,
                $connection->retryDelay,
                fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException
                        && ($exception->response->status() === 429 || $exception->response->serverError())),
                throw: false,
            );
        }

        if ($json) {
            $request = $request->withHeader('Content-Type', 'application/json');
        }

        return $connection->auth->apply($request);
    }

    /**
     * Resolve a `file_url` to something safe to send credentials to.
     *
     * @throws ErpException
     */
    private function resolveFileUrl(Connection $connection, string $fileUrl): string
    {
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $fileUrl)) {
            return $connection->baseUrl.'/'.ltrim($fileUrl, '/');
        }

        if (parse_url($fileUrl, PHP_URL_HOST) === parse_url($connection->baseUrl, PHP_URL_HOST)) {
            return $fileUrl;
        }

        throw new ErpException(
            "Refusing to fetch [{$fileUrl}] with ERPNext credentials: it points outside the configured site."
        );
    }

    /**
     * Guarantee the document name this payload would produce is free.
     *
     * Only doctypes whose name is derived from a field can collide; anything driven by
     * a naming series is skipped without a request. On update the document's own name
     * is excluded server-side, so a document can never collide with itself.
     *
     * @param  array<string, mixed>  $data
     * @param  string|null  $currentName  The document's existing name, on update only.
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    private function ensureUniqueName(string $doctype, array $data, ?string $currentName, ?string $uniqueBy): array
    {
        $field = $this->namingFields[$doctype] ?? null;

        if ($field === null || blank($data[$field] ?? null)) {
            return $data;
        }

        $candidate = (string) $data[$field];

        if ($currentName === $candidate) {
            return $data;
        }

        $query = $this->query($doctype)->fields(['name'])->where('name', '=', $candidate);

        if ($currentName !== null) {
            $query->where('name', '!=', $currentName);
        }

        if (! $query->exists()) {
            return $data;
        }

        if (blank($uniqueBy)) {
            throw new ErpException(
                "ERPNext {$doctype} name [{$candidate}] is already taken. Pass `uniqueBy` to disambiguate it."
            );
        }

        $data[$field] = "{$candidate} ({$uniqueBy})";

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function responseData(Response $response): array
    {
        $data = $response->json();

        return is_array($data) ? $data : [
            'status' => $response->status(),
            'message' => $response->body(),
        ];
    }

    /**
     * ERPNext answers 200 with an `exception` or `_server_messages` body on a rejected
     * document, so a successful status code alone proves nothing.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws ErpException
     */
    private function ensureSuccessful(Response $response, array $body): void
    {
        if (
            $response->successful()
            && isset($body['data'])
            && ! array_key_exists('exception', $body)
            && ! array_key_exists('_server_messages', $body)
        ) {
            return;
        }

        $reason = $body['exception']
            ?? $body['_server_messages']
            ?? $body['message']
            ?? "HTTP {$response->status()}";

        throw new ErpException('ERPNext request failed: '.$this->describe($reason, 500));
    }

    private function describe(mixed $reason, int $limit): string
    {
        return Str::limit(
            is_scalar($reason) ? (string) $reason : json_encode($reason, JSON_THROW_ON_ERROR),
            $limit,
        );
    }
}

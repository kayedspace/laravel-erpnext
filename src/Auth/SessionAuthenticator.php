<?php

namespace Kayedspace\Erpnext\Auth;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Kayedspace\Erpnext\Exceptions\ErpException;

/**
 * Username/password login against `/api/method/login`, replaying the `sid` cookie
 * Frappe hands back.
 *
 * The only stateful scheme. The `sid` is cached so a batch of requests logs in once
 * rather than once per call, and {@see refresh()} discards it so the client can
 * re-establish a session exactly once when the site rejects an expired one.
 */
final class SessionAuthenticator implements Authenticator
{
    private ?string $sid = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly ?string $cacheStore = null,
        private readonly int $cacheTtl = 3600,
    ) {}

    public function apply(PendingRequest $request): PendingRequest
    {
        return $request->withHeader('Cookie', 'sid='.$this->sid());
    }

    public function refresh(): bool
    {
        $this->sid = null;
        $this->cache()->forget($this->cacheKey());

        return true;
    }

    public function fingerprint(): string
    {
        return 'session:'.substr(hash('sha256', $this->baseUrl.'|'.$this->username), 0, 16);
    }

    /**
     * @throws ErpException
     */
    private function sid(): string
    {
        return $this->sid ??= $this->cache()->remember(
            $this->cacheKey(),
            $this->cacheTtl,
            fn (): string => $this->login(),
        );
    }

    /**
     * @throws ErpException
     */
    private function login(): string
    {
        $response = Http::asForm()->post($this->baseUrl.'/api/method/login', [
            'usr' => $this->username,
            'pwd' => $this->password,
        ]);

        /*
         * Frappe returns the session in a Set-Cookie header rather than the body, so
         * the cookie jar is the only place it can be read from.
         */
        $sid = collect($response->cookies()->toArray())
            ->firstWhere('Name', 'sid')['Value'] ?? null;

        if (! $response->successful() || blank($sid)) {
            throw new ErpException(
                "ERPNext login failed for [{$this->username}]: HTTP {$response->status()}."
            );
        }

        return $sid;
    }

    private function cacheKey(): string
    {
        return 'erpnext:'.$this->fingerprint();
    }

    private function cache(): Repository
    {
        return Cache::store($this->cacheStore);
    }
}

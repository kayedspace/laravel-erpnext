<?php

namespace Kayedspace\Erpnext;

use Kayedspace\Erpnext\Auth\Authenticator;
use Kayedspace\Erpnext\Auth\BasicAuthenticator;
use Kayedspace\Erpnext\Auth\BearerAuthenticator;
use Kayedspace\Erpnext\Auth\SessionAuthenticator;
use Kayedspace\Erpnext\Auth\TokenAuthenticator;
use Kayedspace\Erpnext\Exceptions\ErpException;

/**
 * Everything needed to talk to one ERPNext site: where it is, and how to prove who
 * you are. Deliberately nothing else — accounting defaults such as company or cost
 * centre are the consuming application's business, not the client's.
 */
final readonly class Connection
{
    /**
     * Sent on every request so an ERPNext administrator reading the site's access log
     * can tell this client apart from a browser or a stray script.
     */
    public const string USER_AGENT = 'kayedspace/laravel-erpnext';

    /**
     * @param  string  $baseUrl  The site root, e.g. `https://erp.example.com` — no API path.
     * @param  int  $retries  Total attempts for a rate-limited or unavailable site. 1 disables retrying.
     * @param  int  $retryDelay  Milliseconds between those attempts.
     * @param  string  $userAgent  Identifies this client in the site's access logs.
     */
    public function __construct(
        public string $baseUrl,
        public Authenticator $auth,
        public int $timeout = 15,
        public int $connectTimeout = 5,
        public bool $verifySsl = true,
        public int $retries = 3,
        public int $retryDelay = 200,
        public string $userAgent = self::USER_AGENT,
    ) {}

    /**
     * Build a connection from a config array, choosing the authenticator named by
     * `auth_method` and failing loudly when its credentials are incomplete — a clear
     * exception here beats a 403 from six layers down.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws ErpException
     */
    public static function fromArray(array $config): self
    {
        $baseUrl = self::normaliseBaseUrl((string) ($config['base_url'] ?? ''));

        if (blank($baseUrl)) {
            throw new ErpException('ERPNext base URL is missing.');
        }

        return new self(
            baseUrl: $baseUrl,
            auth: self::authenticator($config, $baseUrl),
            timeout: (int) ($config['timeout'] ?? 15),
            connectTimeout: (int) ($config['connect_timeout'] ?? 5),
            verifySsl: (bool) ($config['verify_ssl'] ?? true),
            retries: max(1, (int) ($config['retries'] ?? 3)),
            retryDelay: max(0, (int) ($config['retry_delay'] ?? 200)),
            userAgent: (string) ($config['user_agent'] ?? self::USER_AGENT),
        );
    }

    /**
     * `/api/resource/{doctype}` — the REST endpoint for a doctype's documents.
     */
    public function resourceUrl(string $doctype): string
    {
        return $this->baseUrl.'/api/resource/'.rawurlencode($doctype);
    }

    /**
     * `/api/method/{method}` — the RPC endpoint for whitelisted server methods.
     */
    public function methodUrl(string $method): string
    {
        return $this->baseUrl.'/api/method/'.$method;
    }

    /**
     * Base URL is the bare site root. A configured value that still carries the old
     * `/api/resource` suffix is accepted and trimmed, so upgrading needs no data change.
     */
    private static function normaliseBaseUrl(string $baseUrl): string
    {
        $trimmed = rtrim($baseUrl, '/');

        return rtrim(preg_replace('#/api/resource/?$#', '', $trimmed) ?? $trimmed, '/');
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws ErpException
     */
    private static function authenticator(array $config, string $siteUrl): Authenticator
    {
        $method = (string) ($config['auth_method'] ?? 'token');

        return match ($method) {
            'token' => new TokenAuthenticator(
                self::requireSetting($config, 'api_key', $method),
                self::requireSetting($config, 'api_secret', $method),
            ),
            'basic' => new BasicAuthenticator(
                self::requireSetting($config, 'api_key', $method),
                self::requireSetting($config, 'api_secret', $method),
            ),
            'bearer' => new BearerAuthenticator(
                self::requireSetting($config, 'access_token', $method),
            ),
            'session' => new SessionAuthenticator(
                $siteUrl,
                self::requireSetting($config, 'username', $method),
                self::requireSetting($config, 'password', $method),
                $config['session_cache_store'] ?? null,
                (int) ($config['session_cache_ttl'] ?? 3600),
            ),
            default => throw new ErpException(
                "Unknown ERPNext auth method [{$method}]. Expected one of: token, basic, bearer, session."
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws ErpException
     */
    private static function requireSetting(array $config, string $key, string $method): string
    {
        $value = $config[$key] ?? null;

        if (blank($value)) {
            throw new ErpException("ERPNext auth method [{$method}] requires the `{$key}` setting.");
        }

        return (string) $value;
    }
}

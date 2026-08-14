<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Kayedspace\Erpnext\Auth\BearerAuthenticator;
use Kayedspace\Erpnext\Auth\SessionAuthenticator;
use Kayedspace\Erpnext\Auth\TokenAuthenticator;
use Kayedspace\Erpnext\Client\ErpClient;
use Kayedspace\Erpnext\Connection;
use Kayedspace\Erpnext\Exceptions\ErpException;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Cache::flush();
});

/**
 * @param  array<string, mixed>  $config
 */
function client(array $config): ErpClient
{
    return new ErpClient(fn (): Connection => Connection::fromArray(array_merge([
        'base_url' => 'https://erp.test',
    ], $config)));
}

// -----------------------------------------------------------------------------
// Stateless schemes
// -----------------------------------------------------------------------------

it('signs with a frappe api token by default', function (): void {
    Http::fake(['*' => Http::response(['data' => ['name' => 'CUST-1']])]);

    client(['api_key' => 'k', 'api_secret' => 's'])->find('Customer', 'CUST-1');

    Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'token k:s'));
});

it('signs with http basic when asked', function (): void {
    Http::fake(['*' => Http::response(['data' => ['name' => 'CUST-1']])]);

    client(['auth_method' => 'basic', 'api_key' => 'k', 'api_secret' => 's'])->find('Customer', 'CUST-1');

    Http::assertSent(fn (Request $r): bool => $r->hasHeader(
        'Authorization',
        'Basic '.base64_encode('k:s'),
    ));
});

it('signs with an oauth2 bearer token when asked', function (): void {
    Http::fake(['*' => Http::response(['data' => ['name' => 'CUST-1']])]);

    client(['auth_method' => 'bearer', 'access_token' => 'abc123'])->find('Customer', 'CUST-1');

    Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer abc123'));
});

// -----------------------------------------------------------------------------
// Configuration errors
// -----------------------------------------------------------------------------

it('names the missing setting rather than failing at the site', function (array $config, string $missing): void {
    expect(fn () => client($config)->find('Customer', 'CUST-1'))
        ->toThrow(ErpException::class, "requires the `{$missing}` setting");
})->with([
    'token without secret' => [['api_key' => 'k'], 'api_secret'],
    'basic without key' => [['auth_method' => 'basic', 'api_secret' => 's'], 'api_key'],
    'bearer without token' => [['auth_method' => 'bearer'], 'access_token'],
    'session without password' => [['auth_method' => 'session', 'username' => 'u'], 'password'],
]);

it('rejects an unknown auth method by name', function (): void {
    expect(fn () => client(['auth_method' => 'magic'])->find('Customer', 'CUST-1'))
        ->toThrow(ErpException::class, 'Unknown ERPNext auth method [magic]');
});

it('requires a base url', function (): void {
    $client = new ErpClient(fn (): Connection => Connection::fromArray(['base_url' => '']));

    expect(fn () => $client->find('Customer', 'CUST-1'))
        ->toThrow(ErpException::class, 'base URL is missing');
});

it('accepts a legacy base url that still carries the api resource suffix', function (): void {
    Http::fake(['*' => Http::response(['data' => ['name' => 'CUST-1']])]);

    (new ErpClient(fn (): Connection => Connection::fromArray([
        'base_url' => 'https://erp.test/api/resource/',
        'api_key' => 'k',
        'api_secret' => 's',
    ])))->find('Customer', 'CUST-1');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://erp.test/api/resource/Customer/CUST-1');
});

// -----------------------------------------------------------------------------
// Session auth
// -----------------------------------------------------------------------------

/**
 * @param  array<string, mixed>  $config
 */
function sessionClient(array $config = []): ErpClient
{
    return client(array_merge([
        'auth_method' => 'session',
        'username' => 'admin@example.test',
        'password' => 'secret',
    ], $config));
}

function loginResponse(string $sid = 'SID-1'): PromiseInterface
{
    return Http::response(['message' => 'Logged In'], 200, [
        'Set-Cookie' => "sid={$sid}; Path=/; HttpOnly",
    ]);
}

it('logs in once and replays the cached session on later calls', function (): void {
    Http::fake([
        '*/api/method/login' => loginResponse(),
        '*/api/resource/*' => Http::response(['data' => ['name' => 'CUST-1']]),
    ]);

    $client = sessionClient();
    $client->find('Customer', 'CUST-1');
    $client->find('Customer', 'CUST-2');

    Http::assertSentCount(3); // one login, two reads
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/api/method/login')
        && $r['usr'] === 'admin@example.test'
        && $r['pwd'] === 'secret');
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/api/resource/')
        && $r->hasHeader('Cookie', 'sid=SID-1'));
});

it('re-establishes an expired session exactly once and then succeeds', function (): void {
    Http::fake([
        '*/api/method/login' => Http::sequence()
            ->push(['message' => 'Logged In'], 200, ['Set-Cookie' => 'sid=STALE; Path=/'])
            ->push(['message' => 'Logged In'], 200, ['Set-Cookie' => 'sid=FRESH; Path=/']),
        // The first read is rejected as if the cached session had expired.
        '*/api/resource/*' => Http::sequence()
            ->push(['exc_type' => 'AuthenticationError'], 403)
            ->push(['data' => ['name' => 'CUST-1']], 200),
    ]);

    expect(sessionClient()->find('Customer', 'CUST-1'))->toBe(['name' => 'CUST-1']);

    // login, rejected read, login again, successful read
    Http::assertSentCount(4);
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/api/resource/')
        && $r->hasHeader('Cookie', 'sid=FRESH'));
});

it('gives up after one retry rather than looping on a genuine rejection', function (): void {
    Http::fake([
        '*/api/method/login' => loginResponse(),
        '*/api/resource/*' => Http::response(['exc_type' => 'PermissionError'], 403),
    ]);

    expect(fn () => sessionClient()->find('Customer', 'CUST-1'))->toThrow(ErpException::class);

    // login, read, login, read -- and no third attempt.
    Http::assertSentCount(4);
});

it('does not retry a stateless scheme, which could never recover', function (): void {
    Http::fake(['*' => Http::response(['exc_type' => 'PermissionError'], 403)]);

    expect(fn () => client(['api_key' => 'k', 'api_secret' => 's'])->find('Customer', 'CUST-1'))
        ->toThrow(ErpException::class);

    Http::assertSentCount(1);
});

it('fails clearly when the site rejects the login itself', function (): void {
    Http::fake([
        '*/api/method/login' => Http::response(['message' => 'Invalid login'], 401),
        '*' => Http::response(['data' => []]),
    ]);

    expect(fn () => sessionClient()->find('Customer', 'CUST-1'))
        ->toThrow(ErpException::class, 'ERPNext login failed for [admin@example.test]');
});

it('keeps secrets out of the cache fingerprint', function (): void {
    $session = new SessionAuthenticator('https://erp.test', 'admin@example.test', 'secret');
    $token = new TokenAuthenticator('key-abc', 'secret-xyz');
    $bearer = new BearerAuthenticator('secret-token');

    expect($session->fingerprint())->not->toContain('secret')
        ->and($bearer->fingerprint())->not->toContain('secret-token')
        // The api key is an identifier, not a secret, so it stays readable for debugging.
        ->and($token->fingerprint())->toBe('token:key-abc')
        ->and($token->fingerprint())->not->toContain('secret-xyz');
});

it('separates cached sessions by site and user', function (): void {
    $a = new SessionAuthenticator('https://a.test', 'admin@example.test', 'secret');
    $b = new SessionAuthenticator('https://b.test', 'admin@example.test', 'secret');
    $c = new SessionAuthenticator('https://a.test', 'other@example.test', 'secret');

    expect($a->fingerprint())->not->toBe($b->fingerprint())
        ->and($a->fingerprint())->not->toBe($c->fingerprint());
});

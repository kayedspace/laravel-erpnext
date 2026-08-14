<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kayedspace\Erpnext\Connection;
use Kayedspace\Erpnext\Exceptions\ErpException;
use Kayedspace\Erpnext\Facades\Erpnext;

/**
 * What the client does when the site cannot answer *yet* — a rate limit, a bench
 * restart — as opposed to when it has answered "no".
 */
function configure(array $overrides = []): void
{
    config()->set('erpnext', array_merge([
        'base_url' => 'https://erp.test',
        'auth_method' => 'token',
        'api_key' => 'k',
        'api_secret' => 's',
        'retry_delay' => 0,
    ], $overrides));
}

it('retries a rate-limited request and succeeds on a later attempt', function (): void {
    configure();

    Http::fake(['*' => Http::sequence()
        ->push(status: 429)
        ->push(['data' => ['name' => 'CUST-1']]),
    ]);

    expect(Erpnext::doctype('Customer')->find('CUST-1'))->toBe(['name' => 'CUST-1']);

    Http::assertSentCount(2);
});

it('retries while the site is unavailable', function (): void {
    configure();

    Http::fake(['*' => Http::sequence()
        ->push(status: 503)
        ->push(status: 502)
        ->push(['data' => ['name' => 'CUST-1']]),
    ]);

    expect(Erpnext::doctype('Customer')->find('CUST-1'))->toBe(['name' => 'CUST-1']);

    Http::assertSentCount(3);
});

it('gives up after the configured number of attempts', function (): void {
    configure(['retries' => 2]);

    Http::fake(['*' => Http::response(status: 503)]);

    expect(fn () => Erpnext::doctype('Customer')->find('CUST-1'))->toThrow(ErpException::class);

    Http::assertSentCount(2);
});

it('never retries a rejection that is our own fault', function (): void {
    configure();

    // 417 is Frappe's validation failure. Sending it again could only fail again — and
    // on a write, "again" is how duplicates get made.
    Http::fake(['*' => Http::response(['exception' => 'ValidationError'], 417)]);

    expect(fn () => Erpnext::doctype('Customer')->create(['customer_name' => 'Ada']))
        ->toThrow(ErpException::class);

    Http::assertSentCount(1);
});

it('leaves an authentication failure to the session retry, not the transport one', function (): void {
    configure();

    Http::fake(['*' => Http::response(status: 401)]);

    expect(fn () => Erpnext::doctype('Customer')->find('CUST-1'))->toThrow(ErpException::class);

    // Token auth cannot re-establish anything, so one attempt is the whole budget.
    Http::assertSentCount(1);
});

it('can have retrying switched off entirely', function (): void {
    configure(['retries' => 1]);

    Http::fake(['*' => Http::response(status: 503)]);

    expect(fn () => Erpnext::doctype('Customer')->find('CUST-1'))->toThrow(ErpException::class);

    Http::assertSentCount(1);
});

it('identifies itself in the site access log', function (): void {
    configure();

    Http::fake(['*' => Http::response(['data' => []])]);

    Erpnext::doctype('Customer')->find('CUST-1');

    Http::assertSent(fn (Request $request): bool => $request->header('User-Agent')[0] === Connection::USER_AGENT);
});

it('can be told to identify itself as the host application', function (): void {
    configure(['user_agent' => 'acme-billing/2.1']);

    Http::fake(['*' => Http::response(['data' => []])]);

    Erpnext::doctype('Customer')->find('CUST-1');

    Http::assertSent(fn (Request $request): bool => $request->header('User-Agent')[0] === 'acme-billing/2.1');
});

it('will not send credentials to a host outside the configured site', function (): void {
    configure();

    Http::fake();

    // A file_url is document data, and document data is attacker-writable on any site
    // with more than one user.
    expect(fn () => Erpnext::downloadFile('https://evil.test/private/files/x.pdf'))
        ->toThrow(ErpException::class, 'outside the configured site');

    Http::assertNothingSent();
});

it('still follows an absolute url that belongs to the site', function (): void {
    configure();

    Http::fake(['*' => Http::response('bytes')]);

    expect(Erpnext::downloadFile('https://erp.test/private/files/scan.pdf'))->toBe('bytes');
});

it('resolves a relative file url against the site root', function (): void {
    configure();

    Http::fake(['*' => Http::response('bytes')]);

    Erpnext::downloadFile('/private/files/scan.pdf');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://erp.test/private/files/scan.pdf');
});

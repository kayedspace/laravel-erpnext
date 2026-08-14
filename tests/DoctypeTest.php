<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kayedspace\Erpnext\Client\Doctype;
use Kayedspace\Erpnext\Client\ErpClient;
use Kayedspace\Erpnext\Connection;
use Kayedspace\Erpnext\Exceptions\DocumentNotFoundException;
use Kayedspace\Erpnext\Facades\Erpnext;

/**
 * The generic path: name a doctype, work with it. No subclass, no registration — this
 * is the package's core, and the typed Documents/ classes are sugar on top of it.
 */
beforeEach(function (): void {
    Http::preventStrayRequests();

    app()->instance(ErpClient::class, new ErpClient(
        fn (): Connection => Connection::fromArray([
            'base_url' => 'https://erp.test',
            'api_key' => 'k',
            'api_secret' => 's',
        ]),
        ['Customer' => 'customer_name'],
    ));
});

it('is reachable through the facade', function (): void {
    expect(Erpnext::doctype('Lead'))->toBeInstanceOf(Doctype::class)
        ->and(Erpnext::doctype('Lead')->name())->toBe('Lead');
});

it('reads a document', function (): void {
    Http::fake(['*' => Http::response(['data' => ['name' => 'LEAD-1', 'lead_name' => 'Ada']])]);

    expect(Erpnext::doctype('Lead')->find('LEAD-1')['lead_name'])->toBe('Ada');

    Http::assertSent(fn (Request $r): bool => $r->method() === 'GET'
        && $r->url() === 'https://erp.test/api/resource/Lead/LEAD-1');
});

it('reports a missing document as null, or throws on demand', function (): void {
    Http::fake(['*' => Http::response(['data' => null], 404)]);

    expect(Erpnext::doctype('Lead')->find('NOPE'))->toBeNull();
    expect(fn () => Erpnext::doctype('Lead')->findOrFail('NOPE'))
        ->toThrow(DocumentNotFoundException::class, 'ERPNext Lead [NOPE] was not found.');
});

it('creates, updates and deletes', function (): void {
    Http::fake(['*' => Http::response(['data' => ['name' => 'LEAD-1']])]);

    $lead = Erpnext::doctype('Lead');
    $lead->create(['lead_name' => 'Ada']);
    $lead->update('LEAD-1', ['status' => 'Converted']);
    $lead->delete('LEAD-1');

    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
        && $r->url() === 'https://erp.test/api/resource/Lead');
    Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT'
        && $r->url() === 'https://erp.test/api/resource/Lead/LEAD-1');
    Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE'
        && $r->url() === 'https://erp.test/api/resource/Lead/LEAD-1');
});

it('calls a whitelisted document method', function (): void {
    Http::fake(['*' => Http::response(['message' => 'ok'])]);

    Erpnext::doctype('Sales Invoice')->call('SINV-1', 'submit');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://erp.test/api/method/run_doc_method'
        && $r['dt'] === 'Sales Invoice'
        && $r['dn'] === 'SINV-1'
        && $r['method'] === 'submit');
});

it('carries the naming guarantee through to the generic path', function (): void {
    Http::fake([
        '*fields=*' => Http::response(['data' => [['name' => 'Acme']]]),
        '*' => Http::response(['data' => ['name' => 'CUST-1']]),
    ]);

    Erpnext::doctype('Customer')->create(['customer_name' => 'Acme'], uniqueBy: '7');

    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
        && $r['customer_name'] === 'Acme (7)');
});

it('leaves a doctype it knows nothing about entirely alone', function (): void {
    Http::fake(['*' => Http::response(['data' => ['name' => 'LEAD-1']])]);

    Erpnext::doctype('Lead')->create(['lead_name' => 'Ada']);

    // No naming field configured for Lead, so no probe: one request, the create.
    Http::assertSentCount(1);
});

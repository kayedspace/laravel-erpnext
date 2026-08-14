<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kayedspace\Erpnext\Client\ErpClient;
use Kayedspace\Erpnext\Connection;
use Kayedspace\Erpnext\Exceptions\ErpException;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * A client with the default naming map: Customer and Subscription Plan name themselves
 * from a field, Sales Invoice from a naming series.
 */
function namingClient(): ErpClient
{
    return new ErpClient(
        fn (): Connection => Connection::fromArray([
            'base_url' => 'https://erp.test',
            'api_key' => 'k',
            'api_secret' => 's',
        ]),
        [
            'Customer' => 'customer_name',
            'Subscription Plan' => 'plan_name',
        ],
    );
}

function nameIsFree(): void
{
    Http::fake([
        '*fields=*' => Http::response(['data' => []]),
        '*' => Http::response(['data' => ['name' => 'DOC-1']]),
    ]);
}

function nameIsTaken(): void
{
    Http::fake([
        '*fields=*' => Http::response(['data' => [['name' => 'Ali Hassan']]]),
        '*' => Http::response(['data' => ['name' => 'DOC-1']]),
    ]);
}

// -----------------------------------------------------------------------------
// Create
// -----------------------------------------------------------------------------

it('leaves a free name alone, at the cost of one probe', function (): void {
    nameIsFree();

    namingClient()->create('Customer', ['customer_name' => 'Ali Hassan'], uniqueBy: 'student-42');

    Http::assertSentCount(2); // the probe, then the create
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
        && $r['customer_name'] === 'Ali Hassan');
});

it('appends the caller\'s token when the name is already taken', function (): void {
    nameIsTaken();

    namingClient()->create('Customer', ['customer_name' => 'Ali Hassan'], uniqueBy: 'student-42');

    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
        && $r['customer_name'] === 'Ali Hassan (student-42)');
});

it('probes on the name field, asking only for the name back', function (): void {
    nameIsFree();

    namingClient()->create('Customer', ['customer_name' => 'Ali Hassan'], uniqueBy: '1');

    Http::assertSent(fn (Request $r): bool => $r->method() === 'GET'
        && ($r['filters'] ?? null) === '[["name","=","Ali Hassan"]]'
        && ($r['fields'] ?? null) === '["name"]'
        && (int) $r['limit_page_length'] === 1);
});

it('disambiguates a plan name with the item it belongs to', function (): void {
    Http::fake([
        '*fields=*' => Http::response(['data' => [['name' => 'Anatomy (500/Year)']]]),
        '*' => Http::response(['data' => ['name' => 'PLAN-NEW']]),
    ]);

    namingClient()->create('Subscription Plan', ['plan_name' => 'Anatomy (500/Year)'], uniqueBy: '12TS');

    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
        && $r['plan_name'] === 'Anatomy (500/Year) (12TS)');
});

it('refuses to guess when a name is taken and nothing can disambiguate it', function (): void {
    nameIsTaken();

    expect(fn () => namingClient()->create('Customer', ['customer_name' => 'Ali Hassan']))
        ->toThrow(ErpException::class, 'is already taken. Pass `uniqueBy` to disambiguate it.');
});

// -----------------------------------------------------------------------------
// Doctypes that cannot collide
// -----------------------------------------------------------------------------

it('never probes a doctype named from a series', function (): void {
    Http::fake(['*' => Http::response(['data' => ['name' => 'ACC-SINV-0001']])]);

    namingClient()->create('Sales Invoice', ['customer' => 'CUST-1'], uniqueBy: '7');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST');
});

it('never probes when the payload does not set the naming field', function (): void {
    Http::fake(['*' => Http::response(['data' => ['name' => 'CUST-1']])]);

    namingClient()->create('Customer', ['territory' => 'All Territories'], uniqueBy: '7');

    Http::assertSentCount(1);
});

it('never probes when the naming field is blank', function (): void {
    Http::fake(['*' => Http::response(['data' => ['name' => 'CUST-1']])]);

    namingClient()->create('Customer', ['customer_name' => ''], uniqueBy: '7');

    Http::assertSentCount(1);
});

// -----------------------------------------------------------------------------
// Update
// -----------------------------------------------------------------------------

it('does not probe when the name is unchanged', function (): void {
    Http::fake(['*' => Http::response(['data' => ['name' => 'Ali Hassan']])]);

    namingClient()->update('Customer', 'Ali Hassan', [
        'customer_name' => 'Ali Hassan',
        'custom_mobile' => '123',
    ], uniqueBy: 'student-42');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT');
});

it('excludes the document itself when probing a changed name', function (): void {
    nameIsFree();

    namingClient()->update('Customer', 'Old Name', ['customer_name' => 'Ali Hassan'], uniqueBy: 'student-42');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'GET'
        && ($r['filters'] ?? null) === '[["name","=","Ali Hassan"],["name","!=","Old Name"]]');
});

it('suffixes on update when another document holds the name', function (): void {
    nameIsTaken();

    namingClient()->update('Customer', 'Old Name', ['customer_name' => 'Ali Hassan'], uniqueBy: 'student-42');

    Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT'
        && $r['customer_name'] === 'Ali Hassan (student-42)');
});

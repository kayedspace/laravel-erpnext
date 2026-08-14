<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kayedspace\Erpnext\Client\ErpClient;
use Kayedspace\Erpnext\Connection;
use Kayedspace\Erpnext\Documents\Customer;
use Kayedspace\Erpnext\Documents\SalesInvoice;
use Kayedspace\Erpnext\Documents\SubscriptionPlan;
use Kayedspace\Erpnext\Enums\DocStatus;
use Kayedspace\Erpnext\Exceptions\DocumentNotFoundException;
use Kayedspace\Erpnext\Exceptions\ErpException;

beforeEach(function (): void {
    Http::preventStrayRequests();

    app()->instance(ErpClient::class, new ErpClient(
        fn (): Connection => Connection::fromArray([
            'base_url' => 'https://erp.test',
            'api_key' => 'k',
            'api_secret' => 's',
        ]),
    ));
});

// -----------------------------------------------------------------------------
// Reading
// -----------------------------------------------------------------------------

it('builds the resource url from the bare site root', function (): void {
    Http::fake(['*' => Http::response(['data' => ['name' => 'CUST-1']])]);

    Customer::find('CUST-1');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://erp.test/api/resource/Customer/CUST-1');
});

it('percent-encodes a doctype containing a space', function (): void {
    Http::fake(['*' => Http::response(['data' => []])]);

    SubscriptionPlan::query()->get();

    Http::assertSent(fn (Request $r): bool => str_starts_with(
        $r->url(),
        'https://erp.test/api/resource/Subscription%20Plan',
    ));
});

it('returns null for a missing document and throws on findOrFail', function (): void {
    Http::fake(['*' => Http::response(['data' => null], 404)]);

    expect(Customer::find('NOPE'))->toBeNull();
    expect(fn () => Customer::findOrFail('NOPE'))->toThrow(DocumentNotFoundException::class);
});

it('hydrates from a cached payload without any http', function (): void {
    Http::fake();

    $invoice = SalesInvoice::hydrate(['name' => 'SINV-1', 'docstatus' => 1, 'grand_total' => 90]);

    expect($invoice->exists())->toBeTrue()
        ->and($invoice->name())->toBe('SINV-1')
        ->and($invoice->isSubmitted())->toBeTrue()
        ->and($invoice->grandTotal())->toBe(90.0);

    Http::assertNothingSent();
});

it('reaches any doctype by name, with no class of its own', function (): void {
    Http::fake(['*' => Http::response(['data' => ['name' => 'LEAD-1', 'lead_name' => 'Ada']])]);

    $lead = app(ErpClient::class)->doctype('Lead')->create(['lead_name' => 'Ada']);

    expect($lead['name'])->toBe('LEAD-1');

    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
        && $r->url() === 'https://erp.test/api/resource/Lead');
});

it('scopes a query to the doctype it was opened on', function (): void {
    Http::fake(['*' => Http::response(['data' => []])]);

    app(ErpClient::class)->doctype('Lead')->query()->where('status', 'Open')->get();

    Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://erp.test/api/resource/Lead')
        && ($r['filters'] ?? null) === '[["status","=","Open"]]');
});

// -----------------------------------------------------------------------------
// Writing
// -----------------------------------------------------------------------------

it('creates through post and marks the document as existing', function (): void {
    Http::fake(['*' => Http::response(['data' => ['name' => 'CUST-NEW', 'customer_name' => 'Acme']])]);

    $customer = Customer::create(['customer_name' => 'Acme']);

    expect($customer->exists())->toBeTrue()
        ->and($customer->name())->toBe('CUST-NEW');

    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST');
});

it('saves a new document with post and an existing one with put', function (): void {
    Http::fake(['*' => Http::response(['data' => ['name' => 'CUST-1', 'customer_name' => 'Acme']])]);

    (new Customer(['customer_name' => 'Acme']))->save();
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST');

    Customer::hydrate(['name' => 'CUST-1'])->fill(['customer_name' => 'Acme Ltd'])->save();
    Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT'
        && $r->url() === 'https://erp.test/api/resource/Customer/CUST-1');
});

it('replaces its attributes from the update response', function (): void {
    Http::fake(['*' => Http::response(['data' => ['name' => 'CUST-1', 'customer_name' => 'Renamed']])]);

    $customer = Customer::hydrate(['name' => 'CUST-1', 'customer_name' => 'Old'])
        ->update(['customer_name' => 'Renamed']);

    expect($customer->customerName())->toBe('Renamed');
});

it('refuses to address a document that has no name yet', function (): void {
    Http::fake();

    expect(fn () => (new Customer(['customer_name' => 'Acme']))->update([]))
        ->toThrow(ErpException::class, 'has no document name yet');

    Http::assertNothingSent();
});

it('exposes attributes through array access', function (): void {
    $invoice = SalesInvoice::hydrate(['name' => 'SINV-1', 'grand_total' => 90]);

    expect($invoice['grand_total'])->toBe(90)
        ->and(isset($invoice['nope']))->toBeFalse()
        ->and($invoice['nope'])->toBeNull()
        ->and($invoice->toArray())->toBe(['name' => 'SINV-1', 'grand_total' => 90]);
});

// -----------------------------------------------------------------------------
// Submittable lifecycle
// -----------------------------------------------------------------------------

it('reads docstatus as a lifecycle state', function (int $docstatus, DocStatus $expected): void {
    expect(SalesInvoice::hydrate(['name' => 'SINV-1', 'docstatus' => $docstatus])->status())
        ->toBe($expected);
})->with([
    [0, DocStatus::Draft],
    [1, DocStatus::Submitted],
    [2, DocStatus::Cancelled],
]);

it('treats a document with no docstatus as a draft', function (): void {
    expect(SalesInvoice::hydrate(['name' => 'SINV-1'])->isDraft())->toBeTrue();
});

it('submits a draft and reloads it', function (): void {
    Http::fake([
        '*/api/method/run_doc_method' => Http::response(['message' => 'ok']),
        '*' => Http::response(['data' => ['name' => 'SINV-1', 'docstatus' => 1]]),
    ]);

    $invoice = SalesInvoice::hydrate(['name' => 'SINV-1', 'docstatus' => 0])->submit();

    expect($invoice->isSubmitted())->toBeTrue();

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'run_doc_method')
        && $r['method'] === 'submit'
        && $r['dt'] === 'Sales Invoice');
});

it('does not resubmit an already submitted document', function (): void {
    Http::fake();

    SalesInvoice::hydrate(['name' => 'SINV-1', 'docstatus' => 1])->submit();

    Http::assertNothingSent();
});

it('refuses to submit a cancelled document', function (): void {
    Http::fake();

    expect(fn () => SalesInvoice::hydrate(['name' => 'SINV-1', 'docstatus' => 2])->submit())
        ->toThrow(ErpException::class, 'cancelled and cannot be submitted');

    Http::assertNothingSent();
});

it('refuses to cancel a draft, which should be deleted instead', function (): void {
    Http::fake();

    expect(fn () => SalesInvoice::hydrate(['name' => 'SINV-1', 'docstatus' => 0])->cancel())
        ->toThrow(ErpException::class, 'still a draft');

    Http::assertNothingSent();
});

it('cancels a submitted document', function (): void {
    Http::fake([
        '*/api/method/run_doc_method' => Http::response(['message' => 'ok']),
        '*' => Http::response(['data' => ['name' => 'SINV-1', 'docstatus' => 2]]),
    ]);

    expect(SalesInvoice::hydrate(['name' => 'SINV-1', 'docstatus' => 1])->cancel()->isCancelled())
        ->toBeTrue();

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'run_doc_method')
        && $r['method'] === 'cancel');
});

it('amends a cancelled document into a fresh draft that links back', function (): void {
    Http::fake(['*' => Http::response(['data' => ['name' => 'SINV-2', 'docstatus' => 0]])]);

    $amended = SalesInvoice::hydrate([
        'name' => 'SINV-1',
        'docstatus' => 2,
        'creation' => '2026-01-01',
        'owner' => 'someone@example.test',
        'customer' => 'CUST-1',
    ])->amend(['due_date' => '2026-09-01']);

    expect($amended->name())->toBe('SINV-2');

    Http::assertSent(function (Request $r): bool {
        $body = $r->data();

        return $r->method() === 'POST'
            && $body['amended_from'] === 'SINV-1'
            && $body['docstatus'] === 0
            && $body['customer'] === 'CUST-1'
            && $body['due_date'] === '2026-09-01'
            // Server-assigned identity must not be carried into the new document.
            && ! array_key_exists('name', $body)
            && ! array_key_exists('creation', $body)
            && ! array_key_exists('owner', $body);
    });
});

it('refuses to amend a document that is not cancelled', function (): void {
    Http::fake();

    expect(fn () => SalesInvoice::hydrate(['name' => 'SINV-1', 'docstatus' => 1])->amend())
        ->toThrow(ErpException::class, 'must be cancelled before it can be amended');

    Http::assertNothingSent();
});

it('falls back to the grand total when a draft carries no outstanding amount', function (): void {
    expect(SalesInvoice::hydrate(['name' => 'SINV-1', 'grand_total' => 90])->outstandingAmount())
        ->toBe(90.0)
        ->and(SalesInvoice::hydrate(['name' => 'SINV-1', 'grand_total' => 90, 'outstanding_amount' => 30])
            ->outstandingAmount())->toBe(30.0);
});

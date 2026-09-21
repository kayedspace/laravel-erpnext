<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kayedspace\Erpnext\Client\ErpClient;
use Kayedspace\Erpnext\Client\ErpQuery;
use Kayedspace\Erpnext\Connection;

/**
 * These assert the serialised shape only — no HTTP is involved, so the client is a
 * throwaway with a connection that is never resolved.
 */
function query(string $doctype = 'Customer'): ErpQuery
{
    $client = new ErpClient(fn (): Connection => Connection::fromArray([
        'base_url' => 'https://erp.test/api/resource',
        'api_key' => 'k',
        'api_secret' => 's',
    ]));

    return $client->query($doctype);
}

it('sends nothing but defaults when no conditions are added', function (): void {
    expect(query()->toRequestParams())->toBe([
        'fields' => '["*"]',
        'limit_page_length' => 100,
    ]);
});

it('puts a lone condition in filters and never in or_filters', function (): void {
    $params = query()->where('custom_platform_id', '=', 42)->toRequestParams();

    expect($params['filters'])->toBe('[["custom_platform_id","=",42]]')
        ->and($params)->not->toHaveKey('or_filters');
});

it('keeps and and or conditions in separate buckets', function (): void {
    $params = query()
        ->where('disabled', '=', 0)
        ->orWhere('custom_platform_id', '=', 42)
        ->orWhere('name', '=', 'Acme')
        ->toRequestParams();

    expect($params['filters'])->toBe('[["disabled","=",0]]')
        ->and($params['or_filters'])->toBe('[["custom_platform_id","=",42],["name","=","Acme"]]');
});

it('reads a two-argument where as equality', function (): void {
    expect(query()->where('status', 'Active')->toRequestParams()['filters'])
        ->toBe('[["status","=","Active"]]');
});

it('does not mistake an explicit null value for the two-argument form', function (): void {
    expect(query()->where('ends_at', '=', null)->toRequestParams()['filters'])
        ->toBe('[["ends_at","=",null]]');
});

it('serialises whereIn as frappe expects', function (): void {
    expect(query()->whereIn('status', ['Active', 'Paid'])->toRequestParams()['filters'])
        ->toBe('[["status","in",["Active","Paid"]]]');
});

it('carries fields, ordering, limit and offset', function (): void {
    $params = query()
        ->fields(['name', 'grand_total'])
        ->orderBy('creation', 'asc')
        ->limit(25)
        ->offset(50)
        ->toRequestParams();

    expect($params['fields'])->toBe('["name","grand_total"]')
        ->and($params['order_by'])->toBe('creation asc')
        ->and($params['limit_page_length'])->toBe(25)
        ->and($params['limit_start'])->toBe(50);
});

it('serialises selected link expansions as a json array', function (): void {
    $query = query()->fields(['name', 'priority'])->expand(['priority']);

    expect($query->toRequestParams()['expand'])->toBe('["priority"]')
        ->and($query->expand([])->toRequestParams())->not->toHaveKey('expand');
});

it('defaults ordering to descending', function (): void {
    expect(query()->orderBy('modified')->toRequestParams()['order_by'])->toBe('modified desc');
});

it('leaves the builder untouched when narrowing to the first result', function (): void {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['data' => [['name' => 'CUST-1']]])]);

    $query = query()->where('name', '=', 'CUST-1')->limit(50);

    expect($query->first())->toBe(['name' => 'CUST-1'])
        // first() clones before capping, so the original limit survives a later get().
        ->and($query->toRequestParams()['limit_page_length'])->toBe(50);

    Http::assertSent(fn (Request $request): bool => (int) $request['limit_page_length'] === 1);
});

<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kayedspace\Erpnext\Facades\Erpnext;

/**
 * Frappe caps a list request at `limit_page_length` rows, so anything that must see a
 * whole result set has to walk it a page at a time. These cover that walk: the offsets
 * it asks for, and — just as importantly — when it stops asking.
 */
beforeEach(function (): void {
    config()->set('erpnext', [
        'base_url' => 'https://erp.test',
        'auth_method' => 'token',
        'api_key' => 'k',
        'api_secret' => 's',
        'retries' => 1,
    ]);
});

/**
 * Fake one response per page, each holding the number of rows given, with document
 * names running consecutively across the whole sequence so a walk can be asserted
 * end to end.
 *
 * @param  array<int, int>  $pageSizes
 */
function pagesOf(array $pageSizes): void
{
    $sequence = Http::sequence();
    $row = 0;

    foreach ($pageSizes as $size) {
        $rows = [];

        for ($i = 0; $i < $size; $i++) {
            $rows[] = ['name' => 'CUST-'.++$row];
        }

        $sequence->push(['data' => $rows]);
    }

    // Any request past the end of the script answers empty rather than blowing up, so a
    // test that over-fetches fails on its own assertion instead of an OutOfBounds.
    Http::fake(['*' => $sequence->whenEmpty(Http::response(['data' => []]))]);
}

/**
 * A list request carries its parameters in the query string, not a body, so they have
 * to be read back off the URL.
 *
 * @return array<string, string>
 */
function queryOf(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parameters);

    /** @var array<string, string> $parameters */
    return $parameters;
}

it('walks every page until one comes back short', function (): void {
    pagesOf([2, 2, 1]);

    $seen = [];
    Erpnext::query('Customer')->limit(2)->each(function (array $row) use (&$seen): void {
        $seen[] = $row['name'];
    });

    expect($seen)->toBe(['CUST-1', 'CUST-2', 'CUST-3', 'CUST-4', 'CUST-5']);

    Http::assertSentCount(3);
});

it('advances the offset by one page at a time', function (): void {
    pagesOf([2, 0]);

    iterator_to_array(Erpnext::query('Customer')->limit(2)->chunk());

    $offsets = [];
    Http::assertSent(function (Request $request) use (&$offsets): bool {
        $offsets[] = queryOf($request)['limit_start'] ?? null;

        return true;
    });

    expect($offsets)->toBe(['0', '2']);
});

it('stops after a full final page returns nothing, not before', function (): void {
    pagesOf([2, 2, 0]);

    expect(iterator_to_array(Erpnext::query('Customer')->limit(2)->lazy()))->toHaveCount(4);

    Http::assertSentCount(3);
});

it('makes no second request when the first page is already short', function (): void {
    pagesOf([1]);

    expect(iterator_to_array(Erpnext::query('Customer')->limit(50)->lazy()))->toHaveCount(1);

    Http::assertSentCount(1);
});

it('stops paging the moment the callback returns false', function (): void {
    pagesOf([2, 2, 2]);

    $seen = [];
    Erpnext::query('Customer')->limit(2)->each(function (array $row) use (&$seen): bool {
        $seen[] = $row['name'];

        return count($seen) < 3;
    });

    // Two requests, because stopping mid-page still means that page was fetched.
    expect($seen)->toHaveCount(3);
    Http::assertSentCount(2);
});

it('passes the index of each row, counting across page boundaries', function (): void {
    pagesOf([2, 1]);

    $indexes = [];
    Erpnext::query('Customer')->limit(2)->each(function (array $row, int $index) use (&$indexes): void {
        $indexes[] = $index;
    });

    expect($indexes)->toBe([0, 1, 2]);
});

it('honours an explicit page size over the builder limit', function (): void {
    pagesOf([1]);

    iterator_to_array(Erpnext::query('Customer')->limit(100)->chunk(1));

    Http::assertSent(fn (Request $request): bool => queryOf($request)['limit_page_length'] === '1');
});

it('starts from the builder offset when one was set', function (): void {
    pagesOf([1]);

    iterator_to_array(Erpnext::query('Customer')->limit(2)->offset(10)->chunk());

    Http::assertSent(fn (Request $request): bool => queryOf($request)['limit_start'] === '10');
});

it('counts the whole result set rather than one page', function (): void {
    Http::fake(['*' => Http::response([
        'data' => array_map(fn (int $i): array => ['name' => "CUST-{$i}"], range(1, 250)),
    ])]);

    expect(Erpnext::query('Customer')->count())->toBe(250);

    // limit_page_length=0 is how Frappe is asked for every row, and only `name` is
    // fetched because nothing else is being counted.
    Http::assertSent(fn (Request $request): bool => queryOf($request)['limit_page_length'] === '0'
        && queryOf($request)['fields'] === '["name"]');
});

it('leaves the builder untouched when counting', function (): void {
    Http::fake(['*' => Http::response(['data' => []])]);

    $query = Erpnext::query('Customer')->fields(['name', 'customer_name'])->limit(5);
    $query->count();

    expect($query->toRequestParams()['fields'])->toBe('["name","customer_name"]')
        ->and($query->toRequestParams()['limit_page_length'])->toBe(5);
});

it('leaves the builder untouched when plucking', function (): void {
    Http::fake(['*' => Http::response(['data' => [['name' => 'CUST-1']]])]);

    $query = Erpnext::query('Customer')->fields(['name', 'customer_name']);
    $query->pluck('name');

    expect($query->toRequestParams()['fields'])->toBe('["name","customer_name"]');
});

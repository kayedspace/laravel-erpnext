<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kayedspace\Erpnext\Client\Paginator;
use Kayedspace\Erpnext\Exceptions\ErpException;
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
    Http::fake(['*' => Http::response(['message' => 250])]);

    expect(Erpnext::query('Customer')
        ->where('disabled', 0)
        ->orWhere('territory', 'Egypt')
        ->count())->toBe(250);

    Http::assertSent(fn (Request $request): bool => str_contains(
        $request->url(),
        '/api/method/frappe.desk.reportview.get_count',
    ) && queryOf($request)['doctype'] === 'Customer'
        && queryOf($request)['filters'] === '[["disabled","=","0"]]'
        && queryOf($request)['or_filters'] === '[["territory","=","Egypt"]]'
        && ! isset(queryOf($request)['fields'], queryOf($request)['limit_page_length']));
    Http::assertSentCount(1);
});

it('leaves the builder untouched when counting', function (): void {
    Http::fake(['*' => Http::response(['message' => 0])]);

    $query = Erpnext::query('Customer')->fields(['name', 'customer_name'])->limit(5);
    $query->count();

    expect($query->toRequestParams()['fields'])->toBe('["name","customer_name"]')
        ->and($query->toRequestParams()['limit_page_length'])->toBe(5);
});

it('rejects an invalid server count', function (mixed $count): void {
    Http::fake(['*' => Http::response(['message' => $count])]);

    expect(fn (): int => Erpnext::query('Customer')->count())
        ->toThrow(ErpException::class);
})->with([[-1], [1.5], [null], [['count' => 1]]]);

it('fetches only the requested page after the server-side count', function (): void {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'frappe.desk.reportview.get_count')) {
            return Http::response(['message' => 52]);
        }

        return Http::response(['data' => [
            ['name' => 'CUST-51'],
            ['name' => 'CUST-52'],
        ]]);
    });

    $page = Erpnext::query('Customer')->paginate(perPage: 25, page: 3);

    expect($page)->toBeInstanceOf(Paginator::class)
        ->and($page->items())->toBe([['name' => 'CUST-51'], ['name' => 'CUST-52']])
        ->and($page->total())->toBe(52)
        ->and($page->perPage())->toBe(25)
        ->and($page->currentPage())->toBe(3)
        ->and($page->lastPage())->toBe(3)
        ->and($page->firstItem())->toBe(51)
        ->and($page->lastItem())->toBe(52)
        ->and($page->previousPage())->toBe(2)
        ->and($page->nextPage())->toBeNull()
        ->and($page->onLastPage())->toBeTrue()
        ->and($page)->toHaveCount(2)
        ->and(iterator_to_array($page))->toBe($page->items())
        ->and($page->toArray())->toMatchArray([
            'current_page' => 3,
            'per_page' => 25,
            'from' => 51,
            'to' => 52,
            'total' => 52,
            'last_page' => 3,
            'previous_page' => 2,
            'next_page' => null,
        ]);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/resource/Customer')
        && queryOf($request)['limit_start'] === '50'
        && queryOf($request)['limit_page_length'] === '25');
    Http::assertSentCount(2);
});

it('navigates by fetching one page without recounting', function (): void {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'frappe.desk.reportview.get_count')) {
            return Http::response(['message' => 5]);
        }

        $start = (int) (queryOf($request)['limit_start'] ?? 0);
        $rows = [];

        foreach (range($start + 1, min($start + 2, 5)) as $row) {
            $rows[] = ['name' => "CUST-{$row}"];
        }

        return Http::response(['data' => $rows]);
    });

    $first = Erpnext::query('Customer')->paginate(2);
    $second = $first->next();
    $third = $first->forPage(3);
    $firstAgain = $second?->previous();

    expect($first->onFirstPage())->toBeTrue()
        ->and($first->previous())->toBeNull()
        ->and($first->forPage(1))->toBe($first)
        ->and($second?->currentPage())->toBe(2)
        ->and($second?->items())->toBe([['name' => 'CUST-3'], ['name' => 'CUST-4']])
        ->and($third->items())->toBe([['name' => 'CUST-5']])
        ->and($third->next())->toBeNull()
        ->and($firstAgain?->items())->toBe($first->items());

    Http::assertSentCount(5);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'frappe.desk.reportview.get_count'));
    expect(Http::recorded(fn (Request $request): bool => str_contains(
        $request->url(),
        'frappe.desk.reportview.get_count',
    )))->toHaveCount(1);
});

it('skips the page request when the count is zero', function (): void {
    Http::fake(['*' => Http::response(['message' => 0])]);

    $page = Erpnext::query('Customer')->paginate();

    expect($page->items())->toBe([])
        ->and($page->total())->toBe(0)
        ->and($page->firstItem())->toBeNull()
        ->and($page->lastItem())->toBeNull()
        ->and($page->lastPage())->toBe(1);
    Http::assertSentCount(1);
});

it('requires positive page values', function (int $perPage, int $page): void {
    Http::fake();

    expect(fn (): Paginator => Erpnext::query('Customer')->paginate($perPage, $page))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([[0, 1], [15, 0], [-1, 1]]);

it('requires a positive navigation page', function (): void {
    Http::fake(['*' => Http::response(['message' => 0])]);

    $page = Erpnext::query('Customer')->paginate();

    expect(fn (): Paginator => $page->forPage(0))->toThrow(InvalidArgumentException::class);
    Http::assertSentCount(1);
});

it('leaves the builder untouched when plucking', function (): void {
    Http::fake(['*' => Http::response(['data' => [['name' => 'CUST-1']]])]);

    $query = Erpnext::query('Customer')->fields(['name', 'customer_name']);
    $query->pluck('name');

    expect($query->toRequestParams()['fields'])->toBe('["name","customer_name"]');
});

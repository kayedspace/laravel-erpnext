<?php

namespace Kayedspace\Erpnext\Client;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use InvalidArgumentException;
use Kayedspace\Erpnext\Exceptions\ErpException;

/**
 * Fluent builder over Frappe's `/api/resource/{doctype}` list endpoint.
 *
 * Frappe has no nested filter groups: it takes an AND group in `filters` and an OR
 * group in `or_filters`, and ANDs the two together. So {@see where()} and
 * {@see orWhere()} fill different buckets and are not interchangeable — a lone
 * `where()` serialises to `filters`, never `or_filters`.
 */
class ErpQuery
{
    /** @var array<int, array{0: string, 1: string, 2: mixed}> */
    private array $filters = [];

    /** @var array<int, array{0: string, 1: string, 2: mixed}> */
    private array $orFilters = [];

    /** @var array<int, string> */
    private array $fields = ['*'];

    private int $limit = 100;

    private ?int $offset = null;

    private ?string $orderBy = null;

    public function __construct(
        private readonly ErpClient $client,
        private readonly string $doctype,
    ) {}

    /**
     * Two-argument sugar: `where('status', 'Active')` means equality.
     */
    public function where(string $field, string $operator, mixed $value = null): static
    {
        $this->filters[] = func_num_args() < 3
            ? [$field, '=', $operator]
            : [$field, $operator, $value];

        return $this;
    }

    public function orWhere(string $field, string $operator, mixed $value = null): static
    {
        $this->orFilters[] = func_num_args() < 3
            ? [$field, '=', $operator]
            : [$field, $operator, $value];

        return $this;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    public function whereIn(string $field, array $values): static
    {
        return $this->where($field, 'in', array_values($values));
    }

    /**
     * @param  array<int, string>  $fields
     */
    public function fields(array $fields): static
    {
        $this->fields = $fields;

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offset = $offset;

        return $this;
    }

    public function orderBy(string $field, string $direction = 'desc'): static
    {
        $this->orderBy = $field.' '.$direction;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function get(): array
    {
        return $this->client->search($this->doctype, $this->toRequestParams());
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function first(): ?array
    {
        $document = (clone $this)->limit(1)->get()[0] ?? null;

        return is_array($document) ? $document : null;
    }

    /**
     * @throws ConnectionException
     * @throws ErpException
     */
    public function exists(): bool
    {
        return $this->first() !== null;
    }

    /**
     * The total number of matching documents on the site, not the size of a page.
     *
     * Frappe performs the count server-side and returns one integer. Prefer
     * {@see exists()} when the answer you actually want is "any?".
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function count(): int
    {
        return $this->client->count($this->doctype, array_filter([
            'filters' => $this->encode($this->filters),
            'or_filters' => $this->encode($this->orFilters),
        ]));
    }

    /**
     * Fetch one page and its server-side total without loading the full result set.
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function paginate(int $perPage = 15, int $page = 1): Paginator
    {
        if ($perPage < 1 || $page < 1) {
            throw new InvalidArgumentException('Page and per-page values must be at least 1.');
        }

        $query = clone $this;
        $total = $query->count();
        $items = $total === 0 ? [] : (clone $query)
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        return new Paginator($query, $items, $total, $perPage, $page);
    }

    /**
     * Walk every matching document, one page at a time.
     *
     * A list request is capped at `limit()` rows, so anything that must see the whole
     * result set has to page through it. This does that with one request per page and
     * only one page resident at a time — the reason to reach for it over `get()` on
     * any doctype that might grow.
     *
     * Return `false` from the callback to stop early.
     *
     * @param  callable(array<string, mixed>, int): (bool|void)  $callback  Receives each row and its zero-based index.
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function each(callable $callback): void
    {
        $index = 0;

        foreach ($this->chunk() as $page) {
            foreach ($page as $document) {
                if ($callback($document, $index++) === false) {
                    return;
                }
            }
        }
    }

    /**
     * Page through the results, yielding one page of rows at a time.
     *
     * Frappe pages by offset, so a document created or deleted mid-walk can shift the
     * window and cause a row to be seen twice or skipped. Order by an immutable column
     * such as `creation` when that matters.
     *
     * @param  int|null  $pageSize  Rows per request; defaults to the builder's own limit.
     * @return Generator<int, array<int, array<string, mixed>>>
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function chunk(?int $pageSize = null): Generator
    {
        $pageSize = $pageSize ?? ($this->limit > 0 ? $this->limit : 100);
        $offset = $this->offset ?? 0;

        do {
            $page = (clone $this)->limit($pageSize)->offset($offset)->get();

            if ($page === []) {
                return;
            }

            yield $page;

            $offset += $pageSize;
        } while (count($page) === $pageSize);
    }

    /**
     * Every matching document as one lazily-produced stream of rows.
     *
     * @return Generator<int, array<string, mixed>>
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function lazy(?int $pageSize = null): Generator
    {
        foreach ($this->chunk($pageSize) as $page) {
            /*
             * Yielded one at a time rather than with `yield from`, which would restart
             * the keys at zero on every page — enough to make iterator_to_array()
             * silently collapse the whole walk down to a single page.
             */
            foreach ($page as $document) {
                yield $document;
            }
        }
    }

    /**
     * @return array<int, mixed>
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function pluck(string $field): array
    {
        return array_column((clone $this)->fields([$field])->get(), $field);
    }

    /**
     * The serialised query string parameters. Exposed so the shape can be asserted
     * without standing up an HTTP fake.
     *
     * @return array<string, mixed>
     */
    public function toRequestParams(): array
    {
        return array_filter([
            'filters' => $this->encode($this->filters),
            'or_filters' => $this->encode($this->orFilters),
            'fields' => json_encode($this->fields, JSON_THROW_ON_ERROR),
            'order_by' => $this->orderBy,
            'limit_start' => $this->offset,
            'limit_page_length' => $this->limit,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: mixed}>  $conditions
     */
    private function encode(array $conditions): ?string
    {
        return $conditions === [] ? null : json_encode($conditions, JSON_THROW_ON_ERROR);
    }
}

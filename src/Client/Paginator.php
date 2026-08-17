<?php

namespace Kayedspace\Erpnext\Client;

use ArrayIterator;
use Countable;
use Illuminate\Http\Client\ConnectionException;
use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;
use Kayedspace\Erpnext\Exceptions\ErpException;
use Traversable;

/**
 * One server-fetched page plus enough state to fetch adjacent pages.
 *
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final class Paginator implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function __construct(
        private readonly ErpQuery $query,
        private readonly array $items,
        private readonly int $total,
        private readonly int $perPage,
        private readonly int $currentPage,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function items(): array
    {
        return $this->items;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function firstItem(): ?int
    {
        return $this->items === [] ? null : (($this->currentPage - 1) * $this->perPage) + 1;
    }

    public function lastItem(): ?int
    {
        return $this->items === [] ? null : $this->firstItem() + count($this->items) - 1;
    }

    public function previousPage(): ?int
    {
        return $this->currentPage > 1 ? $this->currentPage - 1 : null;
    }

    public function nextPage(): ?int
    {
        return $this->hasMorePages() ? $this->currentPage + 1 : null;
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage();
    }

    public function onFirstPage(): bool
    {
        return $this->currentPage === 1;
    }

    public function onLastPage(): bool
    {
        return $this->currentPage >= $this->lastPage();
    }

    /**
     * @throws ConnectionException
     * @throws ErpException
     */
    public function previous(): ?self
    {
        $page = $this->previousPage();

        return $page === null ? null : $this->forPage($page);
    }

    /**
     * @throws ConnectionException
     * @throws ErpException
     */
    public function next(): ?self
    {
        $page = $this->nextPage();

        return $page === null ? null : $this->forPage($page);
    }

    /**
     * Fetch one page without repeating the server-side count.
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function forPage(int $page): self
    {
        if ($page < 1) {
            throw new InvalidArgumentException('Page must be at least 1.');
        }

        if ($page === $this->currentPage) {
            return $this;
        }

        // ponytail: total is a navigation snapshot; call paginate() again when a live total matters.
        $items = $this->total === 0 ? [] : (clone $this->query)
            ->limit($this->perPage)
            ->offset(($page - 1) * $this->perPage)
            ->get();

        return new self($this->query, $items, $this->total, $this->perPage, $page);
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return Traversable<int, array<string, mixed>> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * @return array{
     *     data: array<int, array<string, mixed>>,
     *     current_page: int,
     *     per_page: int,
     *     from: int|null,
     *     to: int|null,
     *     total: int,
     *     last_page: int,
     *     previous_page: int|null,
     *     next_page: int|null
     * }
     */
    public function toArray(): array
    {
        return [
            'data' => $this->items,
            'current_page' => $this->currentPage,
            'per_page' => $this->perPage,
            'from' => $this->firstItem(),
            'to' => $this->lastItem(),
            'total' => $this->total,
            'last_page' => $this->lastPage(),
            'previous_page' => $this->previousPage(),
            'next_page' => $this->nextPage(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

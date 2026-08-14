<?php

namespace Kayedspace\Erpnext\Documents;

use ArrayAccess;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Client\ConnectionException;
use JsonSerializable;
use Kayedspace\Erpnext\Client\ErpClient;
use Kayedspace\Erpnext\Client\ErpQuery;
use Kayedspace\Erpnext\Enums\DocStatus;
use Kayedspace\Erpnext\Exceptions\DocumentNotFoundException;
use Kayedspace\Erpnext\Exceptions\ErpException;

/**
 * An optional typed wrapper around one ERPNext doctype.
 *
 * This is a convenience layer, not the foundation. ERPNext is a generic document store
 * and the client speaks to any doctype by name — `Erpnext::doctype('Lead')->create(...)`
 * needs no class at all. Subclass this only where named accessors and a lifecycle are
 * worth the file, and reach for the client directly for everything else.
 *
 * The client is resolved from the container on every use rather than held, so a
 * document never pins one tenant's credentials into a later request.
 *
 * @implements ArrayAccess<string, mixed>
 * @implements Arrayable<string, mixed>
 */
abstract class Document implements Arrayable, ArrayAccess, JsonSerializable
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    final public function __construct(
        protected array $attributes = [],
        protected bool $exists = false,
    ) {}

    /**
     * The ERPNext doctype this class represents, e.g. `Sales Invoice`.
     */
    abstract public static function doctype(): string;

    protected static function client(): ErpClient
    {
        return app(ErpClient::class);
    }

    public static function query(): ErpQuery
    {
        return static::client()->query(static::doctype());
    }

    /**
     * Wrap an already-fetched payload without touching the network. Use it to revive a
     * document from a cached response.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function hydrate(array $attributes): static
    {
        return new static($attributes, exists: filled($attributes['name'] ?? null));
    }

    /**
     * @throws ConnectionException
     * @throws ErpException
     */
    public static function find(string $name): ?static
    {
        $attributes = static::client()->find(static::doctype(), $name);

        return $attributes === null ? null : new static($attributes, exists: true);
    }

    /**
     * @throws ConnectionException
     * @throws ErpException
     */
    public static function findOrFail(string $name): static
    {
        return static::find($name) ?? throw DocumentNotFoundException::for(static::doctype(), $name);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public static function create(array $attributes, ?string $uniqueBy = null): static
    {
        return new static(
            static::client()->create(static::doctype(), $attributes, $uniqueBy),
            exists: true,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function fill(array $attributes): static
    {
        $this->attributes = array_merge($this->attributes, $attributes);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function update(array $attributes, ?string $uniqueBy = null): static
    {
        $this->attributes = static::client()->update(
            static::doctype(),
            $this->requireName(),
            $attributes,
            $uniqueBy,
        );
        $this->exists = true;

        return $this;
    }

    /**
     * Persist the current attributes, creating the document if it has never been saved.
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function save(?string $uniqueBy = null): static
    {
        $this->attributes = $this->exists
            ? static::client()->update(static::doctype(), $this->requireName(), $this->attributes, $uniqueBy)
            : static::client()->create(static::doctype(), $this->attributes, $uniqueBy);

        $this->exists = true;

        return $this;
    }

    /**
     * @throws ConnectionException
     * @throws ErpException
     */
    public function refresh(): static
    {
        $this->attributes = static::client()->findOrFail(static::doctype(), $this->requireName());
        $this->exists = true;

        return $this;
    }

    /**
     * @throws ConnectionException
     * @throws ErpException
     */
    public function delete(): void
    {
        static::client()->delete(static::doctype(), $this->requireName());
        $this->exists = false;
    }

    /**
     * Invoke a whitelisted document method on this document.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function call(string $method, array $args = []): array
    {
        return static::client()->call(static::doctype(), $this->requireName(), $method, $args);
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public function status(): DocStatus
    {
        return DocStatus::tryFrom((int) ($this->attributes['docstatus'] ?? 0)) ?? DocStatus::Draft;
    }

    public function isDraft(): bool
    {
        return $this->status() === DocStatus::Draft;
    }

    public function isSubmitted(): bool
    {
        return $this->status() === DocStatus::Submitted;
    }

    public function isCancelled(): bool
    {
        return $this->status() === DocStatus::Cancelled;
    }

    // -------------------------------------------------------------------------
    // Attributes
    // -------------------------------------------------------------------------

    public function name(): ?string
    {
        $name = $this->attributes['name'] ?? null;

        return is_string($name) && filled($name) ? $name : null;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->attributes[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->attributes;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->attributes[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->attributes[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->attributes[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset]);
    }

    /**
     * @throws ErpException
     */
    protected function requireName(): string
    {
        return $this->name() ?? throw new ErpException(
            'This '.static::doctype().' has no document name yet, so it cannot be addressed in ERPNext.'
        );
    }
}

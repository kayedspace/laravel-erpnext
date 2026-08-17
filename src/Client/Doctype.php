<?php

namespace Kayedspace\Erpnext\Client;

use Illuminate\Http\Client\ConnectionException;
use Kayedspace\Erpnext\Exceptions\ErpException;

/**
 * A doctype-scoped handle on the client — the core way to use this package.
 *
 * ERPNext is a generic document store: every doctype speaks the same REST dialect, and
 * naming one up front is all it takes to work with it. No subclass, no mapping, no
 * registration.
 *
 * ```php
 * Erpnext::doctype('Sales Invoice')->find('ACC-SINV-2025-00001');
 * Erpnext::doctype('Sales Invoice')->query()->where('status', 'Paid')->get();
 * Erpnext::doctype('Lead')->create(['lead_name' => 'Ada Lovelace']);
 * ```
 *
 * The typed classes in `Documents/` are a convenience layer over exactly this, for the
 * handful of doctypes where named accessors earn their keep. They are optional.
 */
class Doctype
{
    private bool $shouldExpandLinks = false;

    public function __construct(
        private readonly ErpClient $client,
        private readonly string $name,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function query(): ErpQuery
    {
        return $this->client->query($this->name);
    }

    /**
     * @return array<string, mixed>|null Null when no such document exists.
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function find(string $name, bool $expandLinks = false): ?array
    {
        return $this->client->find($this->name, $name, $this->shouldExpandLinks || $expandLinks);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function findOrFail(string $name, bool $expandLinks = false): array
    {
        return $this->client->findOrFail($this->name, $name, $this->shouldExpandLinks || $expandLinks);
    }

    /**
     * Expand every link field on the next read without changing this handle.
     */
    public function expandLinks(): static
    {
        $clone = clone $this;
        $clone->shouldExpandLinks = true;

        return $clone;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  string|null  $uniqueBy  Suffix to disambiguate a clashing document name.
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function create(array $data, ?string $uniqueBy = null): array
    {
        return $this->client->create($this->name, $data, $uniqueBy);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function update(string $name, array $data, ?string $uniqueBy = null): array
    {
        return $this->client->update($this->name, $name, $data, $uniqueBy);
    }

    /**
     * @throws ConnectionException
     * @throws ErpException
     */
    public function delete(string $name): void
    {
        $this->client->delete($this->name, $name);
    }

    /**
     * Invoke a whitelisted document method, e.g. `submit`, `cancel`.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function call(string $name, string $method, array $args = []): array
    {
        return $this->client->call($this->name, $name, $method, $args);
    }
}

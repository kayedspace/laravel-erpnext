<?php

namespace Kayedspace\Erpnext\Documents\Concerns;

use Illuminate\Http\Client\ConnectionException;
use Kayedspace\Erpnext\Exceptions\ErpException;

/**
 * The submit → cancel → amend lifecycle Frappe gives submittable doctypes.
 *
 * Frappe enforces the transitions server-side; the guards here exist so a mistake
 * reads as a clear exception instead of an opaque 417 with a serialised traceback.
 *
 * Note the asymmetry: a submitted document is immutable except for fields its
 * doctype marks "allow on submit". Only the site knows which those are, so
 * {@see update()} is left to ERPNext to accept or reject.
 */
trait Submittable
{
    /**
     * @throws ConnectionException
     * @throws ErpException
     */
    public function submit(): static
    {
        if ($this->isSubmitted()) {
            return $this;
        }

        if ($this->isCancelled()) {
            throw new ErpException(
                static::doctype()." [{$this->name()}] is cancelled and cannot be submitted. Amend it instead."
            );
        }

        $this->call('submit');

        return $this->refresh();
    }

    /**
     * @throws ConnectionException
     * @throws ErpException
     */
    public function cancel(): static
    {
        if ($this->isCancelled()) {
            return $this;
        }

        if ($this->isDraft()) {
            throw new ErpException(
                static::doctype()." [{$this->name()}] is still a draft; delete it rather than cancelling it."
            );
        }

        $this->call('cancel');

        return $this->refresh();
    }

    /**
     * Open a fresh draft that supersedes this cancelled document.
     *
     * Frappe models a correction as a new document linked back through
     * `amended_from`, never as an edit — the cancelled original stays on the books.
     *
     * @param  array<string, mixed>  $overrides
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function amend(array $overrides = []): static
    {
        if (! $this->isCancelled()) {
            throw new ErpException(
                static::doctype()." [{$this->name()}] must be cancelled before it can be amended."
            );
        }

        $attributes = array_merge($this->toArray(), $overrides, [
            'amended_from' => $this->name(),
            'docstatus' => 0,
        ]);

        // Server-assigned identity must not be carried over to the new document.
        unset($attributes['name'], $attributes['creation'], $attributes['modified'], $attributes['owner']);

        return static::create($attributes);
    }

    public function isAmendment(): bool
    {
        return filled($this->get('amended_from'));
    }
}

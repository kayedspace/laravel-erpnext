<?php

namespace Kayedspace\Erpnext\Auth;

use Illuminate\Http\Client\PendingRequest;

/**
 * Applies credentials to an outgoing request.
 *
 * Frappe accepts several authentication schemes; each one is an implementation of
 * this interface so the client itself never branches on `auth_method`.
 */
interface Authenticator
{
    public function apply(PendingRequest $request): PendingRequest;

    /**
     * Re-establish credentials after the site rejected them.
     *
     * Stateless schemes (token, basic, bearer) have nothing to re-establish and
     * report false, which is how the client knows a retry would be pointless.
     */
    public function refresh(): bool;

    /**
     * Stable identity for cache keys. Must never include a secret.
     */
    public function fingerprint(): string;
}

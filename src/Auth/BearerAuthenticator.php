<?php

namespace Kayedspace\Erpnext\Auth;

use Illuminate\Http\Client\PendingRequest;

/**
 * OAuth2 access token: `Authorization: Bearer {token}`.
 *
 * Obtaining and rotating the token is the caller's job — supply a fresh one through
 * the connection resolver and every subsequent request picks it up.
 */
final readonly class BearerAuthenticator implements Authenticator
{
    public function __construct(private string $accessToken) {}

    public function apply(PendingRequest $request): PendingRequest
    {
        return $request->withToken($this->accessToken);
    }

    public function refresh(): bool
    {
        return false;
    }

    public function fingerprint(): string
    {
        return 'bearer:'.substr(hash('sha256', $this->accessToken), 0, 16);
    }
}

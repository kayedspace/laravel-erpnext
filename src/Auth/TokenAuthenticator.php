<?php

namespace Kayedspace\Erpnext\Auth;

use Illuminate\Http\Client\PendingRequest;

/**
 * Frappe's API key/secret scheme: `Authorization: token {key}:{secret}`.
 */
final readonly class TokenAuthenticator implements Authenticator
{
    public function __construct(
        private string $apiKey,
        private string $apiSecret,
    ) {}

    public function apply(PendingRequest $request): PendingRequest
    {
        return $request->withHeader('Authorization', "token {$this->apiKey}:{$this->apiSecret}");
    }

    public function refresh(): bool
    {
        return false;
    }

    public function fingerprint(): string
    {
        return 'token:'.$this->apiKey;
    }
}

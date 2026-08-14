<?php

namespace Kayedspace\Erpnext\Auth;

use Illuminate\Http\Client\PendingRequest;

/**
 * HTTP Basic over the API key/secret pair. Frappe accepts this wherever it accepts
 * a token, which makes it the fallback for proxies that strip unknown auth schemes.
 */
final readonly class BasicAuthenticator implements Authenticator
{
    public function __construct(
        private string $apiKey,
        private string $apiSecret,
    ) {}

    public function apply(PendingRequest $request): PendingRequest
    {
        return $request->withHeader(
            'Authorization',
            'Basic '.base64_encode("{$this->apiKey}:{$this->apiSecret}"),
        );
    }

    public function refresh(): bool
    {
        return false;
    }

    public function fingerprint(): string
    {
        return 'basic:'.$this->apiKey;
    }
}

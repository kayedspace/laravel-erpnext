<?php

namespace Kayedspace\Erpnext\Documents;

use Illuminate\Http\Client\ConnectionException;
use Kayedspace\Erpnext\Exceptions\ErpException;

/**
 * Subscription is **not** a submittable doctype: it has no docstatus lifecycle and is
 * wound down through its own `cancel_subscription` / `restart_subscription` methods
 * instead. That is why it does not use the Submittable trait.
 */
class Subscription extends Document
{
    public static function doctype(): string
    {
        return 'Subscription';
    }

    public function subscriptionStatus(): ?string
    {
        return $this->get('status');
    }

    public function isSubscriptionCancelled(): bool
    {
        return $this->subscriptionStatus() === 'Cancelled';
    }

    /**
     * @throws ConnectionException
     * @throws ErpException
     */
    public function cancelSubscription(): static
    {
        return $this->tolerate('cancel_subscription', 'already cancelled');
    }

    /**
     * @throws ConnectionException
     * @throws ErpException
     */
    public function restartSubscription(): static
    {
        return $this->tolerate('restart_subscription', 'not cancelled');
    }

    /**
     * ERPNext raises when cancelling an already-cancelled subscription, or restarting
     * one that was never cancelled. In both cases the two sides already agree, so the
     * complaint is noise rather than a failure — anything else is rethrown.
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    private function tolerate(string $method, string $acceptable): static
    {
        try {
            $this->call($method);
        } catch (ErpException $exception) {
            if (! str_contains(strtolower($exception->getMessage()), $acceptable)) {
                throw $exception;
            }
        }

        return $this;
    }
}

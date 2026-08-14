<?php

namespace Kayedspace\Erpnext\Documents;

class SubscriptionPlan extends Document
{
    /**
     * Days per billing interval. Month is a flat 30 and Year a flat 365: enough to
     * pair a plan with a duration in days, and deliberately not calendar-aware.
     */
    public const array INTERVAL_DAYS = [
        'Year' => 365,
        'Month' => 30,
        'Week' => 7,
        'Day' => 1,
    ];

    public static function doctype(): string
    {
        return 'Subscription Plan';
    }

    public function planName(): ?string
    {
        return $this->get('plan_name');
    }

    public function cost(): float
    {
        return $this->float('cost');
    }

    public function currency(): ?string
    {
        return $this->get('currency') ?: null;
    }

    public function billingInterval(): string
    {
        return (string) $this->get('billing_interval', 'Day');
    }

    public function billingIntervalCount(): int
    {
        return (int) $this->get('billing_interval_count', 0);
    }

    /**
     * The plan's length expressed in days, for comparing against a stored duration.
     */
    public function billingDays(): int
    {
        return (self::INTERVAL_DAYS[$this->billingInterval()] ?? 1) * $this->billingIntervalCount();
    }

    /**
     * Whether the plan costs this, to the cent.
     *
     * ERPNext stores money as a float, so an exact comparison would be a coin toss.
     * Comparing to a tolerance instead invites the opposite mistake: with an epsilon of
     * one cent, a real one-cent difference lands exactly on the boundary and the
     * floating-point representation decides the answer. Rounding both sides to the cent
     * first has no boundary to fall on — 10.00 and 9.99 are different prices, and
     * 10.000001 and 10.0 are the same one.
     */
    public function costMatches(float $price): bool
    {
        return round($this->cost(), 2) === round($price, 2);
    }
}

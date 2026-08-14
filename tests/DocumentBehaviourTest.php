<?php

use Illuminate\Support\Facades\Http;
use Kayedspace\Erpnext\Documents\Customer;
use Kayedspace\Erpnext\Documents\Subscription;
use Kayedspace\Erpnext\Documents\SubscriptionPlan;
use Kayedspace\Erpnext\Enums\DocStatus;
use Kayedspace\Erpnext\Exceptions\ErpException;

/**
 * The parts of the document layer that decide something, as opposed to the named
 * accessors that only read an attribute straight back out.
 */
beforeEach(function (): void {
    config()->set('erpnext', [
        'base_url' => 'https://erp.test',
        'auth_method' => 'token',
        'api_key' => 'k',
        'api_secret' => 's',
        'retries' => 1,
    ]);
});

// ---------------------------------------------------------------------------
// Subscription: tolerated failures
// ---------------------------------------------------------------------------

it('treats cancelling an already-cancelled subscription as agreement, not failure', function (): void {
    Http::fake(['*' => Http::response(['exception' => 'frappe.exceptions.ValidationError: Subscription is already cancelled'], 417)]);

    $subscription = Subscription::hydrate(['name' => 'SUB-1']);

    expect($subscription->cancelSubscription())->toBe($subscription);
});

it('treats restarting a live subscription as agreement, not failure', function (): void {
    Http::fake(['*' => Http::response(['exception' => 'ValidationError: Subscription is not cancelled'], 417)]);

    $subscription = Subscription::hydrate(['name' => 'SUB-1']);

    expect($subscription->restartSubscription())->toBe($subscription);
});

it('rethrows a cancellation failure that is not about being already cancelled', function (): void {
    // The whole risk of tolerating anything is that a real failure goes quiet.
    Http::fake(['*' => Http::response(['exception' => 'PermissionError: not permitted'], 403)]);

    expect(fn () => Subscription::hydrate(['name' => 'SUB-1'])->cancelSubscription())
        ->toThrow(ErpException::class, 'PermissionError');
});

it('rethrows a restart failure that is not about being live already', function (): void {
    Http::fake(['*' => Http::response(['exception' => 'LinkValidationError: missing plan'], 417)]);

    expect(fn () => Subscription::hydrate(['name' => 'SUB-1'])->restartSubscription())
        ->toThrow(ErpException::class, 'LinkValidationError');
});

it('reports a cancelled subscription from its own status field, not a docstatus', function (): void {
    // Subscription has no submittable lifecycle; its status is a plain string.
    expect(Subscription::hydrate(['name' => 'SUB-1', 'status' => 'Cancelled'])->isSubscriptionCancelled())->toBeTrue()
        ->and(Subscription::hydrate(['name' => 'SUB-1', 'status' => 'Active'])->isSubscriptionCancelled())->toBeFalse()
        ->and(Subscription::hydrate(['name' => 'SUB-1'])->subscriptionStatus())->toBeNull();
});

// ---------------------------------------------------------------------------
// Money
// ---------------------------------------------------------------------------

it('compares a plan cost to the cent, because ERPNext stores money as a float', function (): void {
    $plan = SubscriptionPlan::hydrate(['name' => 'PLAN-1', 'cost' => 10.0]);

    expect($plan->costMatches(10.0))->toBeTrue()
        ->and($plan->costMatches(10.000001))->toBeTrue() // Float noise, same price.
        ->and($plan->costMatches(10.004))->toBeTrue()    // Rounds to the same cent.
        ->and($plan->costMatches(10.02))->toBeFalse()    // A real difference.
        ->and($plan->costMatches(9.99))->toBeFalse();    // One cent is still a price change.
});

it('survives a cost that arrives as a numeric string', function (): void {
    // Frappe is content to send "10.00" where the field is a float.
    expect(SubscriptionPlan::hydrate(['name' => 'PLAN-1', 'cost' => '10.00'])->costMatches(10.0))->toBeTrue();
});

it('reads a missing or non-numeric float as the default rather than zero by accident', function (): void {
    $plan = SubscriptionPlan::hydrate(['name' => 'PLAN-1', 'cost' => null]);

    expect($plan->cost())->toBe(0.0)
        ->and(SubscriptionPlan::hydrate(['name' => 'P', 'cost' => 'free'])->cost())->toBe(0.0);
});

// ---------------------------------------------------------------------------
// Lifecycle
// ---------------------------------------------------------------------------

it('reads the document lifecycle off docstatus', function (): void {
    expect(DocStatus::tryFrom(0))->toBe(DocStatus::Draft)
        ->and(DocStatus::Draft->label())->toBe('Draft')
        ->and(DocStatus::Draft->isEditable())->toBeTrue()
        ->and(DocStatus::Submitted->isEditable())->toBeFalse()
        ->and(DocStatus::Cancelled->isEditable())->toBeFalse()
        ->and(DocStatus::Cancelled->label())->toBe('Cancelled');
});

it('falls back to draft for a docstatus ERPNext has never heard of', function (): void {
    expect(Customer::hydrate(['name' => 'CUST-1', 'docstatus' => 9])->status())->toBe(DocStatus::Draft);
});

it('refetches a document in place', function (): void {
    Http::fake(['*' => Http::response(['data' => ['name' => 'CUST-1', 'customer_name' => 'Acme Ltd']])]);

    $customer = Customer::hydrate(['name' => 'CUST-1', 'customer_name' => 'Stale']);

    expect($customer->refresh()->get('customer_name'))->toBe('Acme Ltd')
        ->and($customer->exists())->toBeTrue();
});

it('refuses to address a document that has no name yet', function (): void {
    Http::fake();

    expect(fn () => Customer::hydrate([])->refresh())
        ->toThrow(ErpException::class, 'no document name yet');

    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Attribute container
// ---------------------------------------------------------------------------

it('behaves as an array over its attributes', function (): void {
    $customer = Customer::hydrate(['name' => 'CUST-1', 'customer_name' => 'Acme']);

    expect(isset($customer['customer_name']))->toBeTrue()
        ->and(isset($customer['nope']))->toBeFalse()
        ->and($customer['customer_name'])->toBe('Acme')
        ->and($customer['nope'])->toBeNull();

    $customer['territory'] = 'Egypt';
    expect($customer['territory'])->toBe('Egypt');

    unset($customer['territory']);
    expect($customer['territory'])->toBeNull();
});

it('serialises to json as the plain attributes ERPNext gave it', function (): void {
    $attributes = ['name' => 'CUST-1', 'customer_name' => 'Acme'];

    expect(json_encode(Customer::hydrate($attributes)))->toBe(json_encode($attributes));
});

it('treats a payload without a name as not yet persisted', function (): void {
    expect(Customer::hydrate(['customer_name' => 'Acme'])->exists())->toBeFalse()
        ->and(Customer::hydrate(['name' => '', 'customer_name' => 'Acme'])->exists())->toBeFalse()
        ->and(Customer::hydrate(['name' => 'CUST-1'])->exists())->toBeTrue();
});

# Contributing

Thanks for taking the time. Bug reports, documentation fixes and pull requests are all
welcome.

## Getting set up

```bash
git clone https://github.com/kayedspace/laravel-erpnext.git
cd laravel-erpnext
composer install
composer test
```

The suite runs on [Testbench](https://github.com/orchestral/testbench), so it boots a
minimal Laravel application with only this package registered. You do not need an
ERPNext site to work on the package — every test fakes HTTP.

## Before you open a pull request

```bash
composer lint     # Pint, using the Laravel preset
composer test     # Pest
```

Both need to pass. `composer lint:test` reports formatting problems without fixing them,
which is what CI runs.

## How the tests are written

Tests are the specification, so they assert what actually goes over the wire rather than
that a method returned something truthy:

```php
Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
    && $r->url() === 'https://erp.test/api/resource/Lead'
    && ($r['filters'] ?? null) === '[["status","=","Open"]]');
```

Two conventions worth keeping:

- **`Http::preventStrayRequests()` in every test.** Any call that escapes the fake must
  fail loudly rather than reach a real site.
- **Assert call counts where they matter.** Several behaviours are defined partly by
  what they *don't* request — the naming check costs one extra GET on some doctypes and
  none on others, and that budget is part of the contract.

## Matching Frappe

When you add support for a Frappe endpoint, check its behaviour against the Frappe
source rather than the REST documentation, which is thin in places and occasionally out
of date. The file upload support, for instance, exists the way it does because
`frappe/handler.py` defaults `is_private` to `1` and never writes the uploaded URL back
onto the parent document's Attach field — neither of which the docs mention.

If a behaviour is surprising, say so in a comment where it lives. A sentence explaining
*why* saves the next person the same archaeology.

## Reporting a bug

Please include the ERPNext/Frappe version, the doctype involved, and the request the
package made — `Http::recorded()` in a failing test is ideal. A failing test is the
fastest possible bug report.

## Security

Do not open a public issue for a security problem. Email <3likayed@gmail.com> instead.

## Code of conduct

By taking part you agree to abide by the [Code of Conduct](CODE_OF_CONDUCT.md).

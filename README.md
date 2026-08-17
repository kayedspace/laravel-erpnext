<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/kayedspace/laravel-erpnext/refs/heads/main/art/logo-lockup-dark.svg">
    <img src="https://raw.githubusercontent.com/kayedspace/laravel-erpnext/refs/heads/main/art/logo-lockup.svg" alt="Laravel ERPNext" width="440">
  </picture>
</p>

<p align="center">
  A fluent ERPNext and Frappe client for Laravel — reach any doctype by name, with a
  query builder, typed documents, four authentication schemes and file uploads.
</p>

<p align="center">
  <a href="https://packagist.org/packages/kayedspace/laravel-erpnext"><img alt="Latest version" src="https://img.shields.io/packagist/v/kayedspace/laravel-erpnext.svg?style=flat-square"></a>
  <a href="https://github.com/kayedspace/laravel-erpnext/actions"><img alt="Tests" src="https://img.shields.io/github/actions/workflow/status/kayedspace/laravel-erpnext/tests.yml?branch=main&label=tests&style=flat-square"></a>
  <a href="https://github.com/kayedspace/laravel-erpnext/actions"><img alt="PHPStan" src="https://img.shields.io/badge/phpstan-level%208-brightgreen.svg?style=flat-square"></a>
  <a href="https://packagist.org/packages/kayedspace/laravel-erpnext"><img alt="Downloads" src="https://img.shields.io/packagist/dt/kayedspace/laravel-erpnext.svg?style=flat-square"></a>
  <a href="LICENSE.md"><img alt="License" src="https://img.shields.io/packagist/l/kayedspace/laravel-erpnext.svg?style=flat-square"></a>
</p>

---

ERPNext is a generic document store. Every doctype — whether it ships with ERPNext or you
invented it this morning — speaks the same REST dialect. This package takes that
seriously: you name a doctype and work with it. No subclass, no mapping, no registration.

```php
use Kayedspace\Erpnext\Facades\Erpnext;

Erpnext::doctype('Lead')->create(['lead_name' => 'Ada Lovelace']);

Erpnext::doctype('Sales Invoice')
    ->query()
    ->where('status', 'Overdue')
    ->fields(['name', 'customer', 'outstanding_amount'])
    ->orderBy('due_date', 'asc')
    ->limit(25)
    ->get();
```

Typed classes exist for the doctypes where named accessors earn their keep, but they are
a convenience layer — never a requirement.

```php
use Kayedspace\Erpnext\Documents\SalesInvoice;

$invoice = SalesInvoice::findOrFail('ACC-SINV-2025-00001');

if ($invoice->isDraft()) {
    $invoice->update(['due_date' => now()->addMonth()->toDateString()]);
    $invoice->submit();
}
```

## Installation

```bash
composer require kayedspace/laravel-erpnext
php artisan vendor:publish --tag=erpnext-config
```

```dotenv
ERPNEXT_BASE_URL=https://erp.example.com
ERPNEXT_AUTH_METHOD=token
ERPNEXT_API_KEY=...
ERPNEXT_API_SECRET=...
```

`ERPNEXT_BASE_URL` is the bare site root. A value that still carries a trailing
`/api/resource` is accepted and trimmed, so upgrading from a hand-rolled client needs no
change to your environment.

## What you get

**Any doctype, by name.** `find`, `findOrFail`, `create`, `update`, `delete`, `query` and
`call` — for `Lead`, `Sales Invoice`, `Custom Widget`, anything.

**A query builder that matches Frappe.** Frappe has no nested filter groups: `filters` is
the AND bucket, `or_filters` the OR bucket, and the two are ANDed together. `where()` and
`orWhere()` fill different buckets and are deliberately not interchangeable, because
pretending otherwise produces queries that quietly return the wrong rows.

**Pagination that does not lie to you.** A list request returns one page, so anything
that has to see the whole set walks it:

```php
Erpnext::doctype('Sales Invoice')->query()->where('status', 'Overdue')->each(
    fn (array $invoice) => Chase::dispatch($invoice['name']),
);
```

`each()`, `chunk()` and `lazy()` request one page at a time and hold one page in memory.
For numbered navigation, `paginate()` asks Frappe for one server-side count and fetches
only the requested page:

```php
$page = Erpnext::query('Customer')->paginate(perPage: 25, page: 3);

$page->items();        // At most 25 customers.
$page->total();        // Scalar count returned by Frappe.
$page->previousPage(); // 2
$page->nextPage();     // 4, or null on the last page.

$next = $page->next();       // One request for page 4; no repeated count.
$fifth = $page->forPage(5);  // One request for page 5.
```

Neither `paginate()` nor `count()` downloads all matching documents. The count runs in
Frappe's database and PHP holds only the current page.

**Four authentication schemes.**

| `auth_method` | Requires | Sends |
|---|---|---|
| `token` *(default)* | `api_key`, `api_secret` | `Authorization: token key:secret` |
| `basic` | `api_key`, `api_secret` | `Authorization: Basic base64(key:secret)` |
| `bearer` | `access_token` | `Authorization: Bearer token` |
| `session` | `username`, `password` | a cached `sid` cookie from `/api/method/login` |

Session logins are cached and re-established automatically — once — when the site rejects
an expired session. Stateless schemes never retry, because a second identical request
could only fail the same way.

**Document name collisions handled for you.** Some doctypes take their document name from
a field rather than a naming series, so two records with the same name collide on insert.
Pass `uniqueBy` and the client checks first, disambiguating only when it has to. It costs
one extra GET on the affected doctypes and nothing at all on the rest.

**The submittable lifecycle.** `docstatus` as a real enum, with `submit()`, `cancel()` and
`amend()` — and guards so a mistake reads as a sentence rather than a 417 with a
traceback in it.

**File uploads, attachments and images.**

```php
$file = Erpnext::upload()
    ->fromPath(storage_path('app/scan.pdf'))
    ->attachTo('Sales Invoice', 'ACC-SINV-2025-00001', 'custom_scan')
    ->store();

$file->url();       // absolute URL
$file->download();  // bytes, authenticated — so private files work too
```

Uploads are **private by default**, matching Frappe. Call `->public()` deliberately: a
public file is readable by anyone with the URL, not merely by signed-in users.

**Retries where they help, and nowhere else.** A 429 from the site's rate limiter, a 5xx
while bench restarts, a refused connection — all retried. A 4xx is not: it means the
request was wrong, and repeating a wrong write is how duplicates get made.

**Multi-tenancy without global state.** The connection is a closure the client re-invokes
on every request, so one long-lived instance can serve many tenants and always
authenticates as whoever is active right now.

```php
$this->app->singleton(ErpClient::class, fn ($app) => new ErpClient(
    fn () => Connection::fromArray($app->make(YourTenantSettings::class)->all()),
));
```

## Testing

Everything routes through Laravel's HTTP client, so the usual tools work and no ERPNext
site is needed:

```php
Http::preventStrayRequests();
Http::fake(['*' => Http::response(['data' => ['name' => 'CUST-0001']])]);

Erpnext::doctype('Customer')->find('CUST-0001');
```

## Documentation

Full guide and API reference: **[laravel-erpnext.kayed.dev](https://laravel-erpnext.kayed.dev)**.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Please note the
[Code of Conduct](CODE_OF_CONDUCT.md).

## Security

Found a security issue? Email <3likayed@gmail.com> rather than opening a public issue.
See [SECURITY.md](.github/SECURITY.md).

## Credits

- [Ali Youssef Kayed](https://github.com/kayedspace)
- [All contributors](https://github.com/kayedspace/laravel-erpnext/contributors)

## License

The MIT License. See [LICENSE.md](LICENSE.md).

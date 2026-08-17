# Changelog

All notable changes to `kayedspace/laravel-erpnext` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.1] - 2026-08-17

### Added

- Lightweight `paginate()` results with totals, page metadata and immutable
  `next()`, `previous()` and `forPage()` navigation.
- Selective list-field expansion with `expand()`, plus opt-in full document link
  expansion through `expandLinks()` and named read options.

### Changed

- `count()` now uses Frappe's server-side SQL count instead of downloading every
  matching document name.

## [0.1] - 2026-08-14

### Added

- Generic doctype access: `Erpnext::doctype('Any Doctype')` for find, create, update,
  delete, query and whitelisted document methods.
- Fluent query builder with Frappe's real filter semantics — `filters` and `or_filters`
  as separate buckets, ANDed together.
- Pagination: `each()`, `chunk()` and `lazy()` walk a whole result set one page per
  request, and `count()` reports the site-wide total rather than one page's size.
- Four authentication schemes: token, basic, bearer and session. Session logins are
  cached and re-established once on rejection.
- Retries for requests the site could not answer yet — a 429 from its rate limiter, a
  5xx during a bench restart, a refused connection — configurable through `retries` and
  `retry_delay`, and never applied to a 4xx the caller caused.
- Typed documents for `Customer`, `Item`, `Company`, `SalesInvoice`, `PaymentEntry`,
  `Subscription`, `SubscriptionPlan` and `File`, as an optional layer over the client.
- The submittable lifecycle: `DocStatus`, `submit()`, `cancel()` and `amend()`, with
  guards for illegal transitions.
- Document-name uniqueness for doctypes named from a field, via an explicit `uniqueBy`.
- File uploads, attachments and image optimisation, including writing the uploaded URL
  onto the target document's Attach field — which Frappe itself does not do.
  Authenticated downloads refuse any URL outside the configured site, because a
  `file_url` is writable document data and the request carries credentials.
- A configurable `User-Agent` on every request, so an application's traffic is
  identifiable in the site's access log.
- `Erpnext` facade.

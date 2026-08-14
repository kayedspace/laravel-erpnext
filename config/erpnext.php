<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Site
    |--------------------------------------------------------------------------
    |
    | The bare site root of your ERPNext installation, e.g.
    | `https://erp.example.com` — no API path. The package appends
    | `/api/resource/...` and `/api/method/...` itself.
    |
    | A legacy value that still ends in `/api/resource` is accepted and trimmed,
    | so upgrading from a hand-rolled client needs no environment change.
    |
    */

    'base_url' => env('ERPNEXT_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | Supported: "token", "basic", "bearer", "session".
    |
    |   token   Authorization: token {api_key}:{api_secret}   (recommended)
    |   basic   Authorization: Basic base64({api_key}:{api_secret})
    |   bearer  Authorization: Bearer {access_token}          (OAuth2)
    |   session POST /api/method/login with {username}/{password}, then the
    |           returned `sid` cookie is cached and replayed. Expired sessions
    |           are re-established once, automatically, on a 401/403.
    |
    */

    'auth_method' => env('ERPNEXT_AUTH_METHOD', 'token'),

    'api_key' => env('ERPNEXT_API_KEY'),
    'api_secret' => env('ERPNEXT_API_SECRET'),
    'access_token' => env('ERPNEXT_ACCESS_TOKEN'),
    'username' => env('ERPNEXT_USERNAME'),
    'password' => env('ERPNEXT_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */

    'timeout' => env('ERPNEXT_TIMEOUT', 15),
    'connect_timeout' => env('ERPNEXT_CONNECT_TIMEOUT', 5),
    'verify_ssl' => env('ERPNEXT_VERIFY_SSL', true),

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | Total attempts, and the pause between them, for requests the site could
    | not answer yet: a 429 from its rate limiter, a 5xx while bench restarts,
    | or a refused connection. Nothing else is retried — a 4xx is our mistake
    | and repeating it only multiplies it. Set `retries` to 1 to disable.
    |
    */

    'retries' => env('ERPNEXT_RETRIES', 3),
    'retry_delay' => env('ERPNEXT_RETRY_DELAY', 200),

    /*
    |--------------------------------------------------------------------------
    | User agent
    |--------------------------------------------------------------------------
    |
    | Sent on every request. Naming your own application here makes this
    | package's traffic identifiable in the ERPNext site's access log.
    |
    */

    'user_agent' => env('ERPNEXT_USER_AGENT', 'kayedspace/laravel-erpnext'),

    /*
    |--------------------------------------------------------------------------
    | Session cache
    |--------------------------------------------------------------------------
    |
    | Where the "session" authenticator stores its `sid`, and for how long.
    | Null uses the default cache store.
    |
    */

    'session_cache_store' => env('ERPNEXT_SESSION_CACHE_STORE'),
    'session_cache_ttl' => env('ERPNEXT_SESSION_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Naming fields
    |--------------------------------------------------------------------------
    |
    | Doctypes whose document `name` is derived from a field rather than from a
    | naming series. For these, and only these, the client checks that the name
    | is free before writing, and disambiguates a collision by appending the
    | `uniqueBy` value passed to create()/update(). Costs one extra GET per
    | write; doctypes absent from this map cost nothing.
    |
    | Match this to your site: with Selling Settings `cust_master_name` set to
    | "Naming Series", Customers name themselves from a series, so removing the
    | Customer entry here saves a pointless probe.
    |
    */

    'naming_fields' => [
        'Customer' => 'customer_name',
        'Subscription Plan' => 'plan_name',
        'Item' => 'item_code',
    ],

];

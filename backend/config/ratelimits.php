<?php

/*
|--------------------------------------------------------------------------
| Per-tenant rate limits (PRD §13, §14)
|--------------------------------------------------------------------------
|
| Noisy-neighbour containment: limits are keyed per TENANT, not per user or
| per IP, so one busy shop cannot spend the budget of every other shop on the
| same Postgres. A shop with six staff on six phones shares one allowance,
| which is the unit that actually maps to load.
|
| Values are per minute. They are deliberately generous relative to what a
| real shop does — a counter clerk entering sales all day is nowhere near
| these — because a limit that trips during honest work is worse than no
| limit at all: it teaches people the app is broken.
|
*/

return [
    // Interactive reads: khata screens, catalog, stock lookups.
    'tenant_read' => (int) env('RATE_LIMIT_TENANT_READ', 300),

    // Interactive writes: sales, payments, stock movements.
    'tenant_write' => (int) env('RATE_LIMIT_TENANT_WRITE', 120),

    // Offline sync is batched — few requests, each carrying many rows — so it
    // gets its own smaller bucket rather than competing with interactive work.
    'sync' => (int) env('RATE_LIMIT_SYNC', 60),

    // Credential stuffing defence, keyed per email+IP rather than per tenant
    // (there is no tenant yet at login).
    'login' => (int) env('RATE_LIMIT_LOGIN', 10),

    // Platform console: cross-tenant by design, so keyed per admin.
    'platform' => (int) env('RATE_LIMIT_PLATFORM', 600),
];

# WhatsApp Credentials in the Console — Design

> **Historical (pre-2026-07-30).** This document predates the PostgreSQL → MySQL 8
> migration; its RLS / `SET LOCAL` / PgBouncer references describe the system as it
> was then, not as it runs now. See
> `docs/superpowers/specs/2026-07-30-postgres-to-mysql-design.md`.

**Date:** 2026-07-25
**Status:** Draft (design); awaiting sign-off.
**Scope:** Let a platform superadmin enter and test the Meta WhatsApp Cloud API
credentials from `/admin/console` instead of editing `.env` and redeploying.
Includes a **Test connection** action, which is how the Phase 4b smoke test
finally gets done.

---

## Background

Phases 4b/4c are complete but inert: `WHATSAPP_DRIVER=log`, and the credentials
that would switch them on live only in `.env`. That means going live requires
shell access and a redeploy, and the 4b smoke test — the precondition 4c's spec
names for enabling automation — has no way to be run at all.

This closes that gap without touching the messaging architecture: still **one
platform WhatsApp number** (4a Decision 3), still no per-tenant credentials.

## Decisions

1. **Platform-level, superadmin-only.** The settings live behind the existing
   `platform_admin.web` gate, in the cross-tenant console. No tenant may read or
   write them; a shop owner never sees this screen.

2. **One row, in a platform-owned table.** `platform_whatsapp_settings` — no
   RLS, no `BelongsToTenant`, like `platform_audit_logs`. There is exactly one
   WhatsApp configuration because there is exactly one number.

3. **Secrets encrypted at rest, and write-only in the UI.** `token`,
   `verify_token` and `app_secret` use Laravel's `encrypted` cast, so the
   database never holds them in plaintext. The form **never renders them back** —
   it shows "set" or "not set", and a blank field means "keep what is stored".
   A secret that can be read out of a page is a secret that leaks through a
   screenshot, a support session, or a browser cache.

4. **Database wins, env is the fallback — and the UI says which is in force.**
   Two sources of truth is a real hazard, so the resolver is explicit: a
   non-empty stored value overrides `config('services.whatsapp.*')`, otherwise
   the env value applies. The screen labels every field with where its live
   value comes from, so nobody debugs a stale token that a `.env` was quietly
   still supplying.

5. **Test connection sends a real message to a number you choose.** This is
   deliberately a real send, not a dry run: the entire point is to verify our
   assumptions against Meta rather than against `Http::fake`. It is audited, and
   the result — message id, or Meta's error code and message verbatim — is shown
   in full, because a truncated integration error is useless.

6. **Every change is audited.** Saving and testing both write `PlatformAudit`
   entries with the actor. Secret *values* are never audited — only that they
   changed.

## Schema

`platform_whatsapp_settings`: `id`, `driver` (default `log`), `api_version`,
`phone_number_id`, `token` (encrypted), `template`, `verify_token` (encrypted),
`app_secret` (encrypted), `updated_by` → users, timestamps.

## Resolution

```
WhatsAppConfig::get($key)  =  stored value if non-empty
                              else config("services.whatsapp.$key")
```

`CloudApiSender`, the container binding, `ReminderController`,
`ReminderDispatcher` and `WhatsAppWebhookController` all read through it, so
there is one answer to "what is the live configuration" everywhere.

## Out of scope

Per-tenant credentials (reversal of 4a Decision 3), template management or
approval from the UI, token-expiry monitoring, and rotating credentials on a
schedule.

## Error handling / edge cases

| Case | Behaviour |
|---|---|
| Blank secret submitted | Existing stored value kept — not wiped. |
| No settings row yet | Everything falls back to env; screen shows "from .env". |
| Test with driver `log` | Refused with an explanation: it would prove nothing. |
| Test before credentials exist | Refused with which fields are missing. |
| Meta returns an error | Shown verbatim, with code, and audited as a failure. |
| Non-admin reaches the URL | Blocked by `platform_admin.web`, as the rest of the console is. |

## Testing

- **Unit** — `WhatsAppConfigTest`: stored value overrides env; empty stored value
  falls back; `source()` reports correctly.
- **Feature** — `ConsoleWhatsAppTest`: non-admin refused; secrets never appear in
  the rendered HTML; a blank secret preserves the stored one; saving writes an
  audit row; the stored token is not plaintext in the database; test-connection
  success and Meta-error paths (`Http::fake`); test refused under the `log`
  driver.
- **Regression** — with no settings row, every existing 4b/4c test still passes,
  proving env-only deployments are unaffected.

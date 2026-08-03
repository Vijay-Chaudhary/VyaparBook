# Deployment — Hostinger shared hosting (as built)

**Live:** https://shreerajshyamaji.com — deployed 2026-08-03
**Server:** `in-mum-web1878.main-hosting.eu` · user `u772825868` · SSH `82.25.107.41:65002`
**Runtime:** PHP 8.3.31 (`/opt/alt/php83/usr/bin/php`) · Composer 2.9.8 · git 2.47.3
**Database:** **MariaDB 11.8.8** (not MySQL 8 — see §3.3)

This is an as-built record, not a plan. Every command here was run against the live box, and the
findings in §3 are things that actually bit during the first deploy.

The app has one property that dictates the whole layout: the service worker registers from `/sw.js`,
and `resources/js/offline/register-sw.js:4` explains why — *"a worker's scope cannot be broader than
its own path."* VyaparBook must own a **domain or subdomain root**. Under `example.com/vyaparbook/`
the offline PWA silently dies, and PRD §9 calls it a hard requirement.

---

## 1. Preflight

Run **all** of these before touching anything. Each one blocked this deploy at some point.

```bash
PHP=/opt/alt/php83/usr/bin/php

$PHP -v                                   # expect 8.3.x
$PHP -r 'echo function_exists("proc_open")?"proc_open OK":"proc_open DISABLED";'
node -v 2>/dev/null || echo 'no node — build assets locally (§7)'
which composer git mysql
```

**The authoritative extension check is `composer check-platform-reqs`, not a hand-written list.**
A hand-written list missed `ext-sodium` on this deploy and cost a round trip. Run it against the
lock file before installing anything:

```bash
cd .../backend && composer check-platform-reqs --no-dev
```

On this box that surfaced exactly one miss: **`ext-sodium`**, required by `lcobucci/jwt 5.6.0` via
`php-open-source-saver/jwt-auth`. `sodium.so` shipped with alt-php but was not enabled.

> **Enable extensions in hPanel → Advanced → PHP Configuration → PHP Extensions.** That covers both
> the CLI and the web SAPI. Confirm it is genuinely just a toggle before sending anyone hunting:
> if `$PHP -d extension=sodium.so -m | grep sodium` loads, the module is present and only disabled.

**Cron granularity** cannot be checked from the shell. Confirm in hPanel → Cron Jobs that the plan
allows an every-minute entry.

---

## 2. Layout

`~/domains/shreerajshyamaji.com/` ships a `DO_NOT_UPLOAD_HERE` marker and a `public_html/`. The
marker means *files here are not web-served* — which is exactly where the application belongs.

```
/home/u772825868/domains/shreerajshyamaji.com/
├── DO_NOT_UPLOAD_HERE          ← Hostinger's marker; leave it
├── vyaparbook/                 ← the git clone (NOT web-reachable)
│   └── backend/
│       ├── app/ bootstrap/ config/ database/ lang/ resources/ routes/
│       ├── vendor/             ← composer install --no-dev
│       ├── storage/            ← 775
│       ├── public/             ← the real docroot contents
│       └── .env                ← chmod 600, outside public_html
└── public_html -> vyaparbook/backend/public      ← symlink
```

`.env` living outside `public_html` is the point: a webserver misconfiguration cannot expose
`APP_KEY`, `JWT_SECRET` or the DB password.

### Docroot symlink

**Verified working — LiteSpeed follows the symlink**, so `public/index.php` needs no path patching.

`public_html` is **not empty** on a fresh domain; it holds Hostinger's `default.php`. Back it up
rather than deleting:

```bash
cd ~/domains/shreerajshyamaji.com
mkdir -p ~/backup-default-docroot && cp -a public_html/. ~/backup-default-docroot/
rm -rf public_html
ln -s vyaparbook/backend/public public_html
readlink -f public_html          # confirm
```

If hPanel ever recreates `public_html` as a real directory the site 404s — recreate the symlink.

---

## 3. What this box does NOT give you

Four findings from the real deploy. None were predicted correctly by the first version of this doc.

### 3.1 The repo is private — `git clone <url>` will not work

The server needs its own credential. Use a **read-only deploy key** — not a PAT, and not agent
forwarding, because this is a shared host and a forwarded personal key would be exposed to anyone
with root on it.

```bash
# on the server
ssh-keygen -t ed25519 -C "vyaparbook-deploy@in-mum-web1878" -f ~/.ssh/id_ed25519_vyaparbook -N "" -q
cat ~/.ssh/id_ed25519_vyaparbook.pub

printf 'Host github.com\n  IdentityFile ~/.ssh/id_ed25519_vyaparbook\n  IdentitiesOnly yes\n  StrictHostKeyChecking accept-new\n' >> ~/.ssh/config
chmod 600 ~/.ssh/config
```

Register it (`gh pr` subcommands are broken on this repo; `gh api` is the working path):

```bash
gh api -X POST repos/Vijay-Chaudhary/VyaparBook/keys \
  -f title="hostinger in-mum-web1878 (read-only deploy)" \
  -f key="ssh-ed25519 AAAA…" -F read_only=true
```

Confirm with `ssh -T git@github.com`. Current key id **159099724**, `read_only: true`.

### 3.2 `proc_open` is DISABLED — and it breaks the scheduler

Two consequences, one of them severe.

**Composer** cannot run scripts. Install with `--no-scripts`, then run discovery directly:

```bash
composer install --no-dev --optimize-autoloader --no-scripts --no-interaction
$PHP artisan package:discover
```

> `package:discover` must run **after `.env` exists**. Without it `DB_CONNECTION` falls back to the
> `sqlite` default at `config/database.php:19`, and `AppServiceProvider::forgetNonMysqlConnections()`
> has deleted that connection — so it dies with *"Database connection [sqlite] not configured."*
> That is the provider working as designed, not a bug.

**The scheduler cannot execute anything.** `Illuminate\Console\Scheduling\Event::execute()` shells
out through Symfony Process. Proven on this box:

```
$ php artisan schedule:test --name="reminders:dispatch"
  Running ['artisan' reminders:dispatch] ... FAIL
  The Process class relies on proc_open, which is not available on your PHP installation.
```

`schedule:run` still exits 0 and prints *"No scheduled commands are ready to run"* when nothing is
due — **so a cron entry looks healthy while delivering nothing.** This is the failure
`bootstrap/app.php:47` warns about ("planned and never sent — safe, but silent"), reached by a route
that comment never anticipated. Never conclude the scheduler works from a quiet `schedule:run`; test
with `schedule:test`.

`php artisan queue:work` and direct `php artisan <command>` invocation both work — neither needs
`proc_open`.

**Fix, in order of preference:**

1. Enable `proc_open` in hPanel (remove it from `disable_functions`). Restores the scheduler and
   Composer scripts.
2. If Hostinger refuses, bypass the scheduler with direct cron calls (§6). This **loses
   `withoutOverlapping()`**, which exists so a slow run cannot double-send. Double-sending WhatsApp
   reminders to a shopkeeper's customers is real harm, so treat this as a stopgap.

### 3.3 The engine is MariaDB, not MySQL 8

`CLAUDE.md` specifies MySQL 8 and `AppServiceProvider` deliberately deletes the `mariadb` connection
at boot. Hostinger provisioned **MariaDB 11.8.8**. The app runs on it through the `mysql` driver,
with every idiom the Postgres→MySQL migration called out verified directly:

| Idiom | Used by | Result |
|---|---|---|
| `json_contains(weekdays, ?)` | `BeatService.php:34` | ✅ `1` |
| `UPDATE … LAST_INSERT_ID(value+1)` | `HasSyncSequence.php:73` | ✅ `11` |
| `regexp_replace` (global by default) | `WhatsAppWebhookController` | ✅ `abc` |
| `CAST(… AS CHAR/SIGNED)` | money-as-string preservation | ✅ |
| **DECIMAL → PHP `string`** | 41 decimal cols, ~2,700 assertions | ✅ `string '1.05'` |

`sql_mode` carries `STRICT_TRANS_TABLES` and `ONLY_FULL_GROUP_BY`, which is what
`config/database.php`'s `'strict' => true` comment depends on.

Note `CAST(x AS JSON)` is **not** valid MariaDB syntax — it is also not something the app does, so it
does not matter here. Beware writing diagnostic queries in MySQL-only syntax and concluding the app
is broken.

> **Open risk:** the Pest suite has never run against MariaDB, and cannot on this box (`--no-dev`
> means Pest is not installed). Run it locally against MariaDB 11.8 before real khata data lands.
> Since the MySQL migration there is no RLS — `BelongsToTenant` failing closed is the *entire*
> isolation layer, and the raw-builder tripwire is test-environment only.

### 3.4 No SELECT-only database user

`config/database.php:119` expects `vyapar_platform_ro` granted `SELECT` and nothing else — the
surviving half of the old Postgres BYPASSRLS role, so the superadmin console *physically cannot*
mutate tenant data. No migration creates it, and hPanel grants all-or-nothing per database.

**Decision taken:** `DB_PLATFORM_USERNAME` points at the app user, with a comment in `.env` recording
why. The console works; the hard guarantee is **not in force**. The only remaining protection is that
platform writes route through `PlatformTenantContext`.

---

## 4. First deploy

```bash
PHP=/opt/alt/php83/usr/bin/php
cd ~/domains/shreerajshyamaji.com

git clone git@github.com:Vijay-Chaudhary/VyaparBook.git vyaparbook
cd vyaparbook/backend

composer install --no-dev --optimize-autoloader --no-scripts --no-interaction
# write .env (§5) BEFORE package:discover — see 3.2
$PHP artisan package:discover

$PHP artisan key:generate --force
$PHP artisan jwt:secret --force        # REQUIRED: .env.example has no JWT_SECRET
chmod 600 .env
chmod -R 775 storage bootstrap/cache

$PHP artisan migrate --force           # 43 tables
$PHP artisan config:cache && $PHP artisan route:cache && $PHP artisan view:cache
```

`composer` is on `PATH` and already runs php83 — do **not** prefix it with the PHP binary.

Smoke test — all verified on the live site:

```bash
curl -sS -o /dev/null -w "%{http_code}\n" https://shreerajshyamaji.com/up      # 200
curl -sS -o /dev/null -w "%{http_code}\n" https://shreerajshyamaji.com/login   # 200
curl -sS -o /dev/null -w "%{http_code}\n" https://shreerajshyamaji.com/sw.js   # 200 — root scope
curl -sS -o /dev/null -w "%{http_code}\n" https://shreerajshyamaji.com/        # 302 → /app
```

`artisan about` fails on this box (it uses Process → `proc_open`). Not a deployment problem.

---

## 5. Production `.env`

```dotenv
APP_NAME=VyaparBook
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Asia/Kolkata        # NOT UTC — see below
APP_URL=https://shreerajshyamaji.com
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=u772825868_vyaparbook
DB_USERNAME=u772825868_vyaparbook
DB_PASSWORD=                     # set with an editor, never a sed one-liner

# Shared hosting cannot grant SELECT-only — see §3.4.
DB_PLATFORM_USERNAME=u772825868_vyaparbook
DB_PLATFORM_PASSWORD="${DB_PASSWORD}"      # phpdotenv interpolation; must come AFTER DB_PASSWORD

SESSION_DRIVER=database
SESSION_ENCRYPT=true
CACHE_STORE=database
QUEUE_CONNECTION=database
WHATSAPP_DRIVER=log              # ships dark; nothing sends until deliberately switched
```

Set `DB_PASSWORD` with `nano`, not `sed` — a password containing `#`, `$` or a space needs quoting,
and an editor sidesteps the trap.

**`JWT_SECRET` is absent from `.env.example`** (only `JWT_TTL`/`JWT_REFRESH_TTL` are there). Without
`artisan jwt:secret` the React app under `/app/*` cannot authenticate at all.

**Redis is not needed.** `CLAUDE.md` lists it, but cache, session and queue all default to `database`
drivers and nothing in `app/` calls `Redis::`. This is why the app fits shared hosting.

### ⚠ `APP_TIMEZONE` is load-bearing

`.env.example` ships UTC. `ReminderDispatcher.php:65` compares `Carbon::now()->hour` against
quiet-hours bounds, and `:74` compares `$now->format('H:i:s')` against the shopkeeper's chosen
`reminder_send_at`. Both read the **app timezone**. On UTC an Indian shop's "10:00" is evaluated
5h30m off and quiet hours guard the wrong part of the day.

Confirm it took effect: `storage/logs/laravel.log` timestamps should read IST while `date` on the
server reads UTC.

---

## 6. Cron

**`crontab` is not on PATH — cron must be configured in hPanel → Cron Jobs.** Use the absolute
CloudLinux PHP path; plain `php` may resolve to a different version.

```cron
# Always. queue:work does not need proc_open.
* * * * * /opt/alt/php83/usr/bin/php /home/u772825868/domains/shreerajshyamaji.com/vyaparbook/backend/artisan queue:work --stop-when-empty --tries=3 --max-time=55 >/dev/null 2>&1

# If proc_open is ENABLED — the correct form:
* * * * * /opt/alt/php83/usr/bin/php .../artisan schedule:run >/dev/null 2>&1

# If proc_open stays DISABLED — stopgap, loses withoutOverlapping (§3.2):
0 6 * * *    /opt/alt/php83/usr/bin/php .../artisan reminders:plan >/dev/null 2>&1
*/15 * * * * /opt/alt/php83/usr/bin/php .../artisan reminders:dispatch >/dev/null 2>&1
```

`--stop-when-empty --max-time=55` is the shared-hosting substitute for a daemonised worker: no
Supervisor exists, so the worker drains the queue and exits before the next tick rather than
stacking processes.

Neither entry blocks launch while `WHATSAPP_DRIVER=log`.

---

## 7. Frontend assets

The server has **no Node**, and `public/build` is gitignored (`backend/.gitignore:3`) so it is not in
the clone. Build locally and copy up:

```bash
# local
cd backend && npm ci && npm run build
scp -P 65002 -r public/build \
  u772825868@82.25.107.41:~/domains/shreerajshyamaji.com/vyaparbook/backend/public/
```

Do this on **every deploy touching `resources/js` or `resources/css`**. A stale `build/` against new
Blade templates fails in ways that look like caching bugs.

The font warnings during build (`/fonts/*.woff2 didn't resolve at build time`) are expected — those
are served from `public/fonts` at runtime.

---

## 8. Redeploying

```bash
PHP=/opt/alt/php83/usr/bin/php
cd ~/domains/shreerajshyamaji.com/vyaparbook && git pull
cd backend
composer install --no-dev --optimize-autoloader --no-scripts --no-interaction
$PHP artisan package:discover
$PHP artisan migrate --force
$PHP artisan config:cache && $PHP artisan route:cache && $PHP artisan view:cache
```

Plus the `scp` from §7 if assets changed. Wrap in `artisan down` / `artisan up` if a migration is not
backward-compatible.

---

## 9. Outstanding

- [ ] Enable `proc_open` in hPanel, then switch cron to `schedule:run` (§3.2).
- [ ] Add the cron entries in hPanel (§6).
- [ ] Run the Pest suite against MariaDB 11.8 locally before real data lands (§3.3).
- [ ] Decide whether the lost SELECT-only guarantee (§3.4) is acceptable long-term.

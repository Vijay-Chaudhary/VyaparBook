# Deployment — Hostinger shared hosting

**Target:** `shreerajshyamaji.com` (tenant #1, per PRD §17)
**Server:** `in-mum-web1878`, user `u772825868`
**Verified available:** git 2.47.3 · Composer 2.9.8 · PHP 8.3.31 (`/opt/alt/php83/usr/bin/php`)

Hostinger's shared tier gives SSH, git and Composer here, so this is a **clone-and-build-on-server**
deploy, not an FTP upload. Only the Vite bundle has to be built elsewhere (see §4).

The app has one property that dictates the whole layout: the service worker is registered from
`/sw.js` at the site root, and `resources/js/offline/register-sw.js:4` explains why — *"a worker's
scope cannot be broader than its own path."* So VyaparBook must own a **domain or subdomain root**.
Deploying under `example.com/vyaparbook/` silently disables the offline PWA, which PRD §9 calls a
hard requirement. `shreerajshyamaji.com` is a domain root, so this is satisfied.

---

## 1. Preflight — run these first

Each of these has blocked a shared-hosting Laravel deploy before. Check them before moving files.

```bash
PHP=/opt/alt/php83/usr/bin/php

# 1. Extensions. bcmath is non-negotiable: every rupee is a decimal string
#    through bcmath and never touches a float (34 files in app/ depend on it).
$PHP -m | grep -Ei '^(bcmath|pdo_mysql|mbstring|openssl|zip|gd|xml|dom|curl|fileinfo|ctype|tokenizer)$'
# Expect all 12. zip + gd are for phpoffice/phpspreadsheet (Excel import/export).

# 2. proc_open — Composer needs it. Many shared hosts disable it.
$PHP -r 'echo function_exists("proc_open") ? "proc_open OK\n" : "proc_open DISABLED\n";'

# 3. Database engine. CLAUDE.md specifies MySQL 8; AppServiceProvider deliberately
#    deletes the mariadb connection at boot. If this says MariaDB, stop and read §8.
mysql -u USER -p -e 'SELECT VERSION();'

# 4. Node — probably absent. If so, the Vite bundle is built locally (§4).
node -v 2>/dev/null || echo 'no node — build assets locally'
```

**Cron granularity** cannot be checked from the shell — confirm in hPanel → Cron Jobs that the plan
allows a **every-minute** entry. Laravel's scheduler assumes per-minute ticks. On plans capped at
15- or 30-minute minimums, `reminders:plan`'s `dailyAt('06:00')` can be skipped entirely.
`bootstrap/app.php:47` states the consequence: reminders are "planned and never sent — safe, but
silent."

---

## 2. Folder layout

`~/domains/shreerajshyamaji.com/` ships with a `DO_NOT_UPLOAD_HERE` marker and a `public_html/`.
That marker means *files here are not web-served* — which is exactly where the application belongs.
Only `public_html/` is reachable over HTTP.

```
/home/u772825868/domains/shreerajshyamaji.com/
├── DO_NOT_UPLOAD_HERE          ← Hostinger's marker; leave it
├── vyaparbook/                 ← the git clone (NOT web-reachable)
│   ├── backend/
│   │   ├── app/  bootstrap/  config/  database/  lang/  resources/  routes/
│   │   ├── vendor/             ← composer install --no-dev
│   │   ├── storage/            ← must be writable
│   │   ├── public/             ← the real docroot contents
│   │   ├── artisan
│   │   └── .env                ← outside public_html, so never web-readable
│   ├── docs/
│   └── CLAUDE.md
└── public_html/                ← symlink → vyaparbook/backend/public   (see §3)
```

Keeping `.env` outside `public_html` is the point of this layout: a webserver misconfiguration
cannot expose `APP_KEY`, `JWT_SECRET` or the DB password.

---

## 3. Docroot: symlink (preferred) or copy

### Option A — symlink `public_html` (recommended)

Redeploys become `git pull` with nothing to re-copy, and `public/index.php` stays unmodified.

```bash
cd ~/domains/shreerajshyamaji.com
rmdir public_html                       # only works if empty — see below if not
ln -s vyaparbook/backend/public public_html
ls -l public_html                       # should show the symlink
```

If `public_html` is not empty, move it aside rather than deleting: `mv public_html public_html.bak`.

Two risks, both worth verifying immediately after linking by loading the site:

- LiteSpeed must follow symlinks (`Options +FollowSymLinks`) — normally on, but confirm.
- hPanel operations can recreate `public_html` as a real directory. If the site 404s after a panel
  change, check whether the symlink survived.

### Option B — copy, if the symlink is rejected

```bash
cd ~/domains/shreerajshyamaji.com
cp -r vyaparbook/backend/public/. public_html/
```

Then edit three paths in `public_html/index.php` (currently lines 8, 13, 16) to reach back into the
app:

```php
if (file_exists($maintenance = __DIR__.'/../vyaparbook/backend/storage/framework/maintenance.php')) {
require __DIR__.'/../vyaparbook/backend/vendor/autoload.php';
(require_once __DIR__.'/../vyaparbook/backend/bootstrap/app.php')
```

With Option B, **every deploy must re-copy `public/`** — including `build/` after an asset change.
That is the standing cost of this option and the reason A is preferred.

---

## 4. Assets: build locally, ship separately

`public/build` is gitignored (`backend/.gitignore:3`), so it is **not** in the clone. If the
preflight found no Node on the server, build on your machine and copy the result up:

```bash
# local
cd backend
npm ci
npm run build                    # → backend/public/build

scp -r public/build \
  u772825868@in-mum-web1878:~/domains/shreerajshyamaji.com/vyaparbook/backend/public/
```

Do this on **every deploy that touches `resources/js` or `resources/css`**. A stale `build/` against
new Blade templates fails in ways that look like caching bugs.

---

## 5. First deploy

```bash
PHP=/opt/alt/php83/usr/bin/php
cd ~/domains/shreerajshyamaji.com

git clone <repo-url> vyaparbook
cd vyaparbook/backend

$PHP /usr/local/bin/composer install --no-dev --optimize-autoloader

cp .env.example .env
# edit .env per §6, then:
$PHP artisan key:generate
$PHP artisan jwt:secret          # REQUIRED — see §6
$PHP artisan migrate --force

chmod -R 775 storage bootstrap/cache

$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
```

Smoke test: `curl -I https://shreerajshyamaji.com/up` — Laravel's health endpoint, registered in
`bootstrap/app.php` via `health: '/up'`. Expect `200`.

---

## 6. Production `.env`

Start from `.env.example` and change these:

```dotenv
APP_ENV=production
APP_DEBUG=false                  # leaking stack traces on a money app is not acceptable
APP_URL=https://shreerajshyamaji.com
APP_TIMEZONE=Asia/Kolkata        # NOT UTC — see the warning below
LOG_LEVEL=warning

DB_DATABASE=<hpanel db>
DB_USERNAME=<hpanel user>
DB_PASSWORD=<hpanel password>

SESSION_ENCRYPT=true

# Leave these on database drivers. Redis is NOT required — see §7.
CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database

# Leave the transport dark until it is deliberately proven against the real API.
WHATSAPP_DRIVER=log
```

**`JWT_SECRET` is missing from `.env.example`** — it lists only `JWT_TTL` and `JWT_REFRESH_TTL`.
`php-open-source-saver/jwt-auth` will not issue tokens without it, so `artisan jwt:secret` in §5 is
mandatory, not optional. Skipping it means the React app under `/app/*` cannot authenticate at all.

### ⚠ `APP_TIMEZONE` is load-bearing for reminders

`.env.example` ships `APP_TIMEZONE=UTC`. `ReminderDispatcher.php:65` compares `Carbon::now()->hour`
against quiet-hours bounds, and line 74 compares `$now->format('H:i:s')` against the shopkeeper's
chosen `reminder_send_at`. Both read the **app timezone**. Left on UTC, an Indian shop that picks
"send at 10:00" gets dispatch evaluated 5h30m off, and the quiet-hours guard protects the wrong part
of the day.

Set `APP_TIMEZONE=Asia/Kolkata` **before the first reminder batch is planned**, and confirm existing
timestamps read correctly afterwards — changing it on a live database changes how every stored
datetime is interpreted.

---

## 7. Redis is not needed

`CLAUDE.md` lists Redis in the stack, but nothing in `app/` calls `Redis::`, and `.env.example`
already routes cache, session and queue to `database`. This is the single reason the app fits shared
hosting at all — keep all three on `database` and ignore the `REDIS_*` block.

---

## 8. Cron

hPanel → Cron Jobs. Note the **CloudLinux PHP path** — plain `php` may resolve to a different
version:

```cron
* * * * * /opt/alt/php83/usr/bin/php /home/u772825868/domains/shreerajshyamaji.com/vyaparbook/backend/artisan schedule:run >> /dev/null 2>&1

* * * * * /opt/alt/php83/usr/bin/php /home/u772825868/domains/shreerajshyamaji.com/vyaparbook/backend/artisan queue:work --stop-when-empty --tries=3 --max-time=55 >> /dev/null 2>&1
```

The second line is the shared-hosting substitute for a daemonised worker: no Supervisor exists here,
so instead of a long-lived process, the worker drains the queue and exits before the next minute's
tick. `--max-time=55` is what prevents processes stacking.

Both are required. The scheduler plans reminder batches; the worker sends them. With only the
scheduler, batches accumulate and nothing is ever delivered.

---

## 9. Redeploying

With Option A (symlink):

```bash
cd ~/domains/shreerajshyamaji.com/vyaparbook
git pull
cd backend
/opt/alt/php83/usr/bin/php /usr/local/bin/composer install --no-dev --optimize-autoloader
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan config:cache && \
/opt/alt/php83/usr/bin/php artisan route:cache && \
/opt/alt/php83/usr/bin/php artisan view:cache
```

Plus the `scp` from §4 if frontend assets changed. Add `php artisan down` before and
`php artisan up` after if a migration is not backward-compatible.

---

## 10. Known gaps on shared hosting

Three things this environment cannot give the app. None block launch; all should be decided
deliberately rather than discovered later.

### The platform read-only DB user

`config/database.php:119` expects `DB_PLATFORM_USERNAME=vyapar_platform_ro`, granted `SELECT` and
nothing else — the surviving half of the old Postgres BYPASSRLS role, so that the superadmin console
*physically cannot* mutate a tenant's data however wrong the code gets. **No migration creates this
user**; it needs a manual `CREATE USER` + `GRANT SELECT`, which shared MySQL accounts generally
cannot issue (hPanel grants all-or-nothing per database).

If SELECT-only is unobtainable, point `DB_PLATFORM_*` at the main app user. The console keeps
working; the hard guarantee is gone, and the only remaining protection is that platform writes route
through `PlatformTenantContext`. Record the decision either way.

### Isolation has no database backstop

Since the MySQL migration there is no RLS. `App\Traits\BelongsToTenant` failing closed is the
*entire* isolation layer in production, and the query tripwire that catches raw builders missing a
`business_id` predicate is **test-environment only** — string-matching SQL was judged too blunt to
gate production traffic on.

Practical consequence: run the full Pest suite against the **same MySQL version the server runs**
before the first tenant's data lands. A version difference is exactly where an untripped raw builder
would surface.

### Decimal fidelity

`config/database.php:82` sets `PDO::ATTR_EMULATE_PREPARES => false`, with the note that under
emulated prepares "PDO hands DECIMAL columns back as PHP floats instead of strings — nothing throws,
and khatas drift by paise. 41 decimal columns and ~2,700 assertions rest on this being false."

If the host's PDO build forces emulation, money corrupts silently. `DecimalFidelityTest` pins this —
run it against the production database as part of go-live, not just in CI.

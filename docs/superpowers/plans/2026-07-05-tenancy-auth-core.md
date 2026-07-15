# Tenancy & Auth Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the Laravel backend for VyaparBook's tenancy & auth core: `Business`/`User`/`Membership`/`Invite`/`OtpCode` models, Postgres RLS isolation with a `SET LOCAL`-per-request middleware safe under PgBouncer transaction pooling, JWT auth (phone OTP + email/password) carrying `tid`/`role` claims, business creation/switch/invite endpoints, and a cross-tenant-leak test suite that proves isolation holds.

**Architecture:** Laravel 11 app in `backend/`, two Postgres connections (`pgsql` — restricted `vyaparbook_app` role, via PgBouncer, used by the running app; `pgsql_migrate` — privileged role, direct to Postgres, used only by migrations/DDL). A `SetTenantContext` middleware opens one transaction per request, sets `app.current_user_id`/`app.current_tenant` Postgres session variables via `SET LOCAL`, and verifies membership before calling the next handler — RLS policies enforce isolation at the DB layer, a `BelongsToTenant` Eloquent trait enforces it again at the app layer for future domain models (defense in depth). `Membership` (the one tenant-scoped table this slice introduces) gets a bespoke RLS policy rather than the flat trait, because it must remain visible/insertable across the pre-tenant-selection window (login, business creation, invite accept).

**Testing:** tests do **not** declare `uses(RefreshDatabase::class)`. Laravel's `RefreshDatabase` is unusable against this design — it drops tables over the restricted `pgsql` role that does not own them; it wraps each test in a `pgsql` transaction whose uncommitted rows are invisible to the `pgsql_migrate` session that setup code needs to bypass RLS (so foreign keys to them fail); and its outer transaction keeps `SET LOCAL` alive across a whole test rather than one request, silently weakening the isolation the RLS tests exist to prove. `tests/Pest.php` instead applies `Tests\RefreshesTenantDatabase` to every test in `Feature`/`Unit`: migrate once per run, `TRUNCATE` as the privileged role between tests, and let every write genuinely commit. `phpunit.xml` points the suite at a dedicated `vyaparbook_test` database so a run never wipes dev data.

**Tech Stack:** PHP 8.3, Laravel 11, PostgreSQL, PgBouncer (transaction pooling), Redis, `php-open-source-saver/jwt-auth`, Pest. No Docker — native local services.

---

## File Structure

```
backend/
  app/
    Models/
      User.php                 (modified — phone, is_platform_admin, JWTSubject)
      Business.php              (new)
      Membership.php            (new)
      Invite.php                 (new)
      OtpCode.php                 (new)
    Http/
      Controllers/Api/V1/
        AuthController.php        (register, login)
        OtpController.php          (otp/request, otp/verify)
        BusinessController.php      (store, mine, switch)
        InviteController.php         (store, accept)
      Middleware/
        SetTenantContext.php          (SET LOCAL wrapper, JWT decode, membership check)
        RequireTenant.php              (guards routes that need an active tid)
    Services/
      TokenService.php                 (issues JWTs with tid/role claims)
      OtpService.php                    (generate/hash/verify OTP codes)
    Support/
      TenantContext.php                  (switchTo() for cross-tenant inserts, forUser() for public auth routes)
    Policies/
      InvitePolicy.php                     (owner/admin-only invite creation)
    Traits/
      BelongsToTenant.php                   (generic tenant global scope, for future domain models)
      TenantAwareJob.php                     (queue job tenant-context wrapper)
  database/
    migrations/
      2026_07_05_000001_create_app_role.php
      2026_07_05_000002_create_businesses_table.php
      2026_07_05_000003_add_tenancy_columns_to_users_table.php
      2026_07_05_000004_create_memberships_table.php
      2026_07_05_000005_create_otp_codes_table.php
      2026_07_05_000006_create_invites_table.php
    factories/
      BusinessFactory.php
      UserFactory.php               (modified)
      MembershipFactory.php
  routes/
    api.php
  config/
    database.php     (modified — pgsql via PgBouncer, pgsql_migrate direct/privileged)
    auth.php          (modified — api guard driver jwt)
    jwt.php            (published from package)
  tests/
    Feature/
      Auth/OtpTest.php
      Auth/RegisterLoginTest.php
      Business/CreateBusinessTest.php
      Business/SwitchBusinessTest.php
      Invite/InviteTest.php
      Tenancy/CrossTenantLeakTest.php
      Tenancy/PgBouncerPooledConnectionTest.php
    Unit/
      BelongsToTenantTraitTest.php
      TenantAwareJobTest.php
    RefreshesTenantDatabase.php   (replaces Laravel's RefreshDatabase — see Testing above)
    TenantDatabaseState.php        (once-per-run migration flag)
    Pest.php                        (modified — applies RefreshesTenantDatabase to Feature/Unit)
  phpunit.xml            (modified — points the suite at the vyaparbook_test database)
  README.md            (setup: Postgres roles, RLS, PgBouncer, .env, running dev)
```

---

## Task 1: Backend scaffold, dual DB connections, Pest

**Files:**
- Create: `backend/` (via `laravel new`)
- Modify: `backend/config/database.php`
- Modify: `backend/.env`, `backend/.env.example`
- Test: `backend/tests/Feature/HealthCheckTest.php`

- [x] **Step 1: Create the Laravel project**

```bash
cd "//wsl.localhost/ubuntu-22.04/home/appuser/workspace/projects/VyaparBook"
composer create-project laravel/laravel backend "^11.0"
cd backend
composer require pestphp/pest pestphp/pest-plugin-laravel --dev --with-all-dependencies
php artisan pest:install
```

- [x] **Step 2: Add the dual Postgres connections**

Edit `backend/config/database.php`, inside the `'connections'` array, replace the default `'pgsql'` entry and add `'pgsql_migrate'`:

```php
'pgsql' => [
    'driver' => 'pgsql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '6432'),
    'database' => env('DB_DATABASE', 'vyaparbook'),
    'username' => env('DB_USERNAME', 'vyaparbook_app'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => 'utf8',
    'prefix' => '',
    'schema' => 'public',
    'sslmode' => 'prefer',
],

'pgsql_migrate' => [
    'driver' => 'pgsql',
    'host' => env('DB_MIGRATE_HOST', '127.0.0.1'),
    'port' => env('DB_MIGRATE_PORT', '5432'),
    'database' => env('DB_MIGRATE_DATABASE', 'vyaparbook'),
    'username' => env('DB_MIGRATE_USERNAME', 'postgres'),
    'password' => env('DB_MIGRATE_PASSWORD', ''),
    'charset' => 'utf8',
    'prefix' => '',
    'schema' => 'public',
    'sslmode' => 'prefer',
],
```

`pgsql` connects through PgBouncer (port 6432) as the restricted `vyaparbook_app` role — this is what the running app uses for every request. `pgsql_migrate` connects directly to Postgres (port 5432) as the privileged `postgres` role — used only by migrations, since DDL (`CREATE TABLE`, `ENABLE ROW LEVEL SECURITY`, `CREATE POLICY`, `CREATE ROLE`) needs privileges the restricted role deliberately does not have.

- [x] **Step 3: Set `.env` and `.env.example`**

Add to both `backend/.env` and `backend/.env.example`:

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=6432
DB_DATABASE=vyaparbook
DB_USERNAME=vyaparbook_app
DB_PASSWORD=

DB_MIGRATE_HOST=127.0.0.1
DB_MIGRATE_PORT=5432
DB_MIGRATE_DATABASE=vyaparbook
DB_MIGRATE_USERNAME=postgres
DB_MIGRATE_PASSWORD=
```

Remove any `DB_CONNECTION=sqlite` default Laravel 11 ships with.

- [x] **Step 4: Write a health-check feature test**

```php
<?php
// tests/Feature/HealthCheckTest.php

it('responds to the root route', function () {
    $this->get('/')->assertStatus(200);
});
```

- [x] **Step 5: Run it to verify the scaffold works**

Run: `cd backend && php artisan test --filter=HealthCheckTest`
Expected: PASS (1 passed)

- [x] **Step 6: Commit**

```bash
git add backend .gitignore
git commit -m "chore: scaffold Laravel backend with dual Postgres connections"
```

---

## Task 2: Postgres app role bootstrap migration

**Files:**
- Create: `backend/database/migrations/2026_07_05_000001_create_app_role.php`
- Test: `backend/tests/Feature/AppRoleMigrationTest.php`

This migration creates the restricted `vyaparbook_app` Postgres role if it doesn't already exist, and grants it the DML (not DDL) privileges it needs. It deliberately does **not** set the role's password from application code — passwords are set once by whoever runs the initial setup (documented in Task 17's README), so a secret never gets embedded in migration SQL or migration history.

- [x] **Step 1: Write the migration**

```php
<?php
// database/migrations/2026_07_05_000001_create_app_role.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql_migrate')->statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = 'vyaparbook_app') THEN
                    CREATE ROLE vyaparbook_app LOGIN;
                END IF;
            END
            $$;
        SQL);

        DB::connection('pgsql_migrate')->statement(
            'GRANT CONNECT ON DATABASE ' . config('database.connections.pgsql_migrate.database') . ' TO vyaparbook_app'
        );
        DB::connection('pgsql_migrate')->statement('GRANT USAGE ON SCHEMA public TO vyaparbook_app');
        DB::connection('pgsql_migrate')->statement(
            'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO vyaparbook_app'
        );
        DB::connection('pgsql_migrate')->statement(
            'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO vyaparbook_app'
        );
        DB::connection('pgsql_migrate')->statement(
            'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO vyaparbook_app'
        );
        DB::connection('pgsql_migrate')->statement(
            'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO vyaparbook_app'
        );
    }

    public function down(): void
    {
        // Intentionally left as a no-op: dropping the role would break the app's
        // connection immediately, and other tables may still depend on grants to it.
    }
};
```

Every migration in this project follows the same pattern: schema/DDL statements go through `DB::connection('pgsql_migrate')` or `Schema::connection('pgsql_migrate')`, never the default `pgsql` connection, because the restricted app role has no DDL rights.

- [x] **Step 2: Run the migration**

Run: `cd backend && php artisan migrate --database=pgsql_migrate`
Expected: `2026_07_05_000001_create_app_role ... DONE`

- [x] **Step 3: Write a test confirming the role exists and lacks superuser/DDL rights**

```php
<?php
// tests/Feature/AppRoleMigrationTest.php

use Illuminate\Support\Facades\DB;

it('creates a non-superuser vyaparbook_app role', function () {
    $role = DB::connection('pgsql_migrate')
        ->selectOne('select rolname, rolsuper from pg_roles where rolname = ?', ['vyaparbook_app']);

    expect($role)->not->toBeNull();
    expect((bool) $role->rolsuper)->toBeFalse();
});
```

- [x] **Step 4: Run the test**

Run: `cd backend && php artisan test --filter=AppRoleMigrationTest`
Expected: PASS

- [x] **Step 5: Commit**

```bash
git add backend/database/migrations backend/tests/Feature/AppRoleMigrationTest.php
git commit -m "feat: bootstrap restricted vyaparbook_app Postgres role"
```

---

## Task 3: Business model + migration

**Files:**
- Create: `backend/database/migrations/2026_07_05_000002_create_businesses_table.php`
- Create: `backend/app/Models/Business.php`
- Create: `backend/database/factories/BusinessFactory.php`
- Test: `backend/tests/Unit/BusinessModelTest.php`

- [x] **Step 1: Write the migration**

```php
<?php
// database/migrations/2026_07_05_000002_create_businesses_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('businesses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('city', 80)->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('default_language', 8)->default('hi');
            $table->string('plan', 20)->default('trial');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('businesses');
    }
};
```

`businesses` has no RLS policy — it's the tenant identity table itself, not tenant-owned data. Any authenticated user can read a business row they're resolving via a valid `Membership`; app-level checks (not RLS) gate that access.

- [x] **Step 2: Write the Business model**

```php
<?php
// app/Models/Business.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Business extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'city', 'gstin', 'default_language', 'plan'];
}
```

- [x] **Step 3: Write the factory**

```php
<?php
// database/factories/BusinessFactory.php

namespace Database\Factories;

use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

class BusinessFactory extends Factory
{
    protected $model = Business::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'city' => $this->faker->city(),
            'default_language' => 'hi',
            'plan' => 'trial',
        ];
    }
}
```

- [x] **Step 4: Write a failing test**

```php
<?php
// tests/Unit/BusinessModelTest.php

use App\Models\Business;

it('generates a uuid primary key on create', function () {
    $business = Business::factory()->create();

    expect($business->id)->toBeString();
    expect(strlen($business->id))->toBe(36);
});
```

- [x] **Step 5: Run the migration and test**

```bash
cd backend
php artisan migrate --database=pgsql_migrate
php artisan test --filter=BusinessModelTest
```
Expected: PASS

- [x] **Step 6: Commit**

```bash
git add backend/database backend/app/Models/Business.php backend/tests/Unit/BusinessModelTest.php
git commit -m "feat: add Business model and migration"
```

---

## Task 4: User model extension

**Files:**
- Create: `backend/database/migrations/2026_07_05_000003_add_tenancy_columns_to_users_table.php`
- Modify: `backend/app/Models/User.php`
- Modify: `backend/database/factories/UserFactory.php`
- Test: `backend/tests/Unit/UserModelTest.php`

- [x] **Step 1: Write the migration**

```php
<?php
// database/migrations/2026_07_05_000003_add_tenancy_columns_to_users_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->table('users', function (Blueprint $table) {
            $table->string('phone', 15)->unique()->nullable()->after('email');
            $table->boolean('is_platform_admin')->default(false);
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'is_platform_admin']);
        });
    }
};
```

Email is made nullable because phone-only OTP signups won't have one.

- [x] **Step 2: Modify the User model**

```php
<?php
// app/Models/User.php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use Notifiable;

    protected $fillable = ['name', 'email', 'phone', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_platform_admin' => 'boolean',
    ];

    public function memberships()
    {
        return $this->hasMany(Membership::class);
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }
}
```

(`getJWTCustomClaims` returns empty here deliberately — `tid`/`role` are attached per-issuance by `TokenService`, built in Task 7, not baked into the model.)

- [x] **Step 3: Update the User factory to include phone**

```php
<?php
// database/factories/UserFactory.php — inside definition(), add:

'phone' => fn () => $this->faker->unique()->numerify('9#########'),
```

- [x] **Step 4: Write a failing test**

```php
<?php
// tests/Unit/UserModelTest.php

use App\Models\User;

it('has a unique phone number', function () {
    $user = User::factory()->create();

    expect($user->phone)->toMatch('/^9\d{9}$/');
});
```

- [x] **Step 5: Run the migration and test**

```bash
cd backend
php artisan migrate --database=pgsql_migrate
php artisan test --filter=UserModelTest
```
Expected: PASS

- [x] **Step 6: Commit**

```bash
git add backend/database backend/app/Models/User.php
git commit -m "feat: add phone and is_platform_admin to users, implement JWTSubject"
```

---

## Task 5: Membership model, migration, and RLS policy

**Files:**
- Create: `backend/database/migrations/2026_07_05_000004_create_memberships_table.php`
- Create: `backend/app/Models/Membership.php`
- Create: `backend/database/factories/MembershipFactory.php`
- Create: `backend/app/Support/TenantContext.php`
- Test: `backend/tests/Feature/Tenancy/MembershipRlsTest.php`

This is the load-bearing task: it establishes the actual RLS policy and the `TenantContext::switchTo()` helper every membership-creating endpoint will need.

- [x] **Step 1: Write the migration**

```php
<?php
// database/migrations/2026_07_05_000004_create_memberships_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->enum('role', ['owner', 'admin', 'salesman', 'accountant']);
            $table->timestamps();
            $table->unique(['user_id', 'business_id']);
        });

        DB::connection('pgsql_migrate')->statement('ALTER TABLE memberships ENABLE ROW LEVEL SECURITY');
        DB::connection('pgsql_migrate')->statement('ALTER TABLE memberships FORCE ROW LEVEL SECURITY');

        DB::connection('pgsql_migrate')->statement(<<<'SQL'
            CREATE POLICY membership_isolation ON memberships
            USING (
                user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint
                OR business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid
            )
            WITH CHECK (
                business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid
            )
        SQL);
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('memberships');
    }
};
```

**Why this exact policy:** `USING` (governs what's visible on SELECT/UPDATE/DELETE) allows a row if it belongs to the current user *or* the current tenant — the user-branch is what lets `GET /businesses/mine` see all of a user's memberships across every business, even before any tenant is selected. `WITH CHECK` (governs INSERT/UPDATE) is strict: a new membership row can only be inserted for whatever business the transaction's `app.current_tenant` is currently set to. Two endpoints (business creation, invite accept) insert a membership for a business that isn't yet the caller's active `tid` — those endpoints must explicitly call `TenantContext::switchTo($targetBusinessId)` right before the insert (see Step 3) to satisfy this check. This keeps the invariant simple and auditable: *you can only ever create a membership for the business your transaction is currently scoped to.*

`current_setting(..., true)` (the `true` second argument) returns `NULL` instead of raising an error when the GUC is unset — necessary because plenty of queries run before any tenant is selected.

- [x] **Step 2: Write the Membership model — no BelongsToTenant trait**

```php
<?php
// app/Models/Membership.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Membership extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['user_id', 'business_id', 'role'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
```

Membership deliberately does **not** use the `BelongsToTenant` trait (built in Task 6): that trait's global scope filters strictly by the current tenant, which would hide a user's memberships in every business except the currently active one — breaking `/businesses/mine`. Membership's visibility rule is the union condition the RLS policy already encodes; the app layer doesn't need a second, different scope on top of it here.

- [x] **Step 3: Write the TenantContext helper**

```php
<?php
// app/Support/TenantContext.php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class TenantContext
{
    /**
     * Re-point the current transaction's tenant GUC at a specific business.
     * Used by endpoints (create-business, invite-accept) that must insert a
     * Membership row for a business other than the caller's active `tid`.
     */
    public static function switchTo(string $businessId): void
    {
        // NOTE: Postgres's `SET` statement grammar does not accept a bind
        // parameter (`$1`) in the value position — `SET LOCAL app.current_tenant = ?`
        // fails with a syntax error on every real Postgres connection, regardless
        // of driver. `set_config(name, value, is_local)` is the parameterizable,
        // semantically identical equivalent: the third argument `true` gives it
        // `SET LOCAL` (transaction-scoped) semantics rather than session-scoped.
        DB::statement("SELECT set_config('app.current_tenant', ?, true)", [$businessId]);
    }

    /**
     * Run $callback in a transaction scoped to a user but no tenant.
     *
     * Public auth routes (login, otp/verify) resolve a user's memberships before
     * any tenant is selected, and they run outside SetTenantContext — so
     * app.current_user_id is unset and the memberships RLS policy hides every
     * row, making membership lookups silently return nothing. Setting the GUC
     * here activates the policy's user_id branch, which exists for exactly this
     * pre-tenant-selection window.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function forUser(int $userId, callable $callback): mixed
    {
        return DB::transaction(function () use ($userId, $callback) {
            DB::statement("SELECT set_config('app.current_user_id', ?, true)", [(string) $userId]);

            return $callback();
        });
    }
}
```

`switchTo()` and `forUser()` are the only two places that touch the tenant GUCs outside `SetTenantContext`. Never write `DB::statement('SET LOCAL <guc> = ?', [...])` anywhere — it is a syntax error against real Postgres, not a portability nicety.

- [x] **Step 4: Write the factory**

```php
<?php
// database/factories/MembershipFactory.php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MembershipFactory extends Factory
{
    protected $model = Membership::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'business_id' => Business::factory(),
            'role' => 'owner',
        ];
    }
}
```

- [x] **Step 5: Write a failing test proving the RLS policy's shape**

This test exercises the raw SQL session variables directly (no HTTP layer yet — that comes in Task 8) to prove the policy itself behaves correctly.

```php
<?php
// tests/Feature/Tenancy/MembershipRlsTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('lets a user see their own membership without a tenant set', function () {
    $user = User::factory()->create();
    $business = Business::factory()->create();
    Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    DB::transaction(function () use ($user) {
        // set_config(name, value, true) is the parameterizable equivalent of
        // `SET LOCAL app.current_user_id = ?` — Postgres's SET statement grammar
        // rejects bind parameters in the value position, so `?`/`$1` here would
        // be a syntax error rather than a GUC assignment. See TenantContext::switchTo().
        DB::statement("SELECT set_config('app.current_user_id', ?, true)", [$user->id]);

        $visible = Membership::where('user_id', $user->id)->count();

        expect($visible)->toBe(1);
    });
});

it('blocks inserting a membership for a business other than the current tenant', function () {
    $user = User::factory()->create();
    $business = Business::factory()->create();
    $otherBusiness = Business::factory()->create();

    expect(function () use ($user, $business, $otherBusiness) {
        DB::transaction(function () use ($user, $business, $otherBusiness) {
            DB::statement("SELECT set_config('app.current_user_id', ?, true)", [$user->id]);
            DB::statement("SELECT set_config('app.current_tenant', ?, true)", [$business->id]);

            Membership::create([
                'user_id' => $user->id,
                'business_id' => $otherBusiness->id, // mismatched on purpose
                'role' => 'owner',
            ]);
        });
    })->toThrow(\Illuminate\Database\QueryException::class);
});
```

- [x] **Step 6: Run the migration and tests**

```bash
cd backend
php artisan migrate --database=pgsql_migrate
php artisan test --filter=MembershipRlsTest
```
Expected: PASS (2 passed)

- [x] **Step 7: Commit**

```bash
git add backend/database backend/app/Models/Membership.php backend/app/Support/TenantContext.php backend/tests/Feature/Tenancy/MembershipRlsTest.php
git commit -m "feat: add Membership model with RLS isolation policy"
```

---

## Task 6: BelongsToTenant trait (shared infra for future domain models)

**Files:**
- Create: `backend/app/Traits/BelongsToTenant.php`
- Test: `backend/tests/Unit/BelongsToTenantTraitTest.php`

No real domain model in this slice uses `BelongsToTenant` (Membership is the exception carved out in Task 5), but the spec commits to building it now as shared infrastructure the next slice's `Product`/`Customer`/`Sale` models will adopt immediately. It's proven here against a throwaway fixture table created inline in the test.

- [x] **Step 1: Write the trait**

```php
<?php
// app/Traits/BelongsToTenant.php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenantId = app('tenant.id');
            if ($tenantId !== null) {
                $builder->where($builder->getModel()->getTable() . '.business_id', $tenantId);
            }
        });

        static::creating(function ($model) {
            if (empty($model->business_id)) {
                $model->business_id = app('tenant.id');
            }
        });
    }
}
```

If `app('tenant.id')` is null (no tenant in scope), the global scope adds no `WHERE` clause at all — relying on RLS alone to block the query. This means the app-level scope is a strict *narrowing*, never a bypass: it can only make a query more restrictive than RLS already is, never less.

- [x] **Step 2: Write a failing unit test against a fixture table**

```php
<?php
// tests/Unit/BelongsToTenantTraitTest.php

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TenantFixtureItem extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_fixture_items';
    protected $guarded = [];
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';
}

beforeEach(function () {
    Schema::connection('pgsql_migrate')->create('tenant_fixture_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('business_id');
        $table->string('name');
    });
});

afterEach(function () {
    Schema::connection('pgsql_migrate')->dropIfExists('tenant_fixture_items');
});

it('stamps business_id from the current tenant on create', function () {
    app()->instance('tenant.id', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');

    $item = TenantFixtureItem::create(['id' => Str::uuid(), 'name' => 'Sev']);

    expect($item->business_id)->toBe('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
});

it('only returns rows for the current tenant', function () {
    app()->instance('tenant.id', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
    TenantFixtureItem::create(['id' => Str::uuid(), 'name' => 'Sev']);

    app()->instance('tenant.id', 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb');
    TenantFixtureItem::create(['id' => Str::uuid(), 'name' => 'Mix']);

    expect(TenantFixtureItem::count())->toBe(1);
    expect(TenantFixtureItem::first()->name)->toBe('Mix');
});
```

- [x] **Step 3: Run the tests**

Run: `cd backend && php artisan test --filter=BelongsToTenantTraitTest`
Expected: PASS (2 passed)

- [x] **Step 4: Commit**

```bash
git add backend/app/Traits/BelongsToTenant.php backend/tests/Unit/BelongsToTenantTraitTest.php
git commit -m "feat: add BelongsToTenant trait for future domain models"
```

---

## Task 7: JWT auth setup and TokenService

**Files:**
- Modify: `backend/config/auth.php`
- Create: `backend/app/Services/TokenService.php`
- Test: `backend/tests/Unit/TokenServiceTest.php`

- [x] **Step 1: Install and configure jwt-auth**

```bash
cd backend
composer require php-open-source-saver/jwt-auth
php artisan vendor:publish --provider="PHPOpenSourceSaver\JWTAuth\Providers\LaravelServiceProvider"
php artisan jwt:secret
```

Add to `.env` and `.env.example`:
```
JWT_TTL=15
JWT_REFRESH_TTL=10080
```
(`JWT_TTL` is in minutes — 15 min access tokens, `JWT_REFRESH_TTL` 10080 min = 7 days, matching the spec.)

- [x] **Step 2: Point the `api` guard at the jwt driver**

Edit `backend/config/auth.php`:

```php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],
    'api' => [
        'driver' => 'jwt',
        'provider' => 'users',
    ],
],
```

- [x] **Step 3: Write the TokenService**

```php
<?php
// app/Services/TokenService.php

namespace App\Services;

use App\Models\Membership;
use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class TokenService
{
    /**
     * Issue a JWT for a user, optionally scoped to an active business membership.
     * When no membership is given, the token carries no tid/role — the client
     * must resolve one via /businesses/mine + /businesses/{id}/switch.
     */
    public function issue(User $user, ?Membership $activeMembership = null): string
    {
        $claims = [
            'tid' => $activeMembership?->business_id,
            'role' => $activeMembership?->role,
        ];

        return JWTAuth::claims($claims)->fromUser($user);
    }
}
```

- [x] **Step 4: Write a failing test**

```php
<?php
// tests/Unit/TokenServiceTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\User;
use App\Services\TokenService;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

it('issues a token with no tid when no membership is given', function () {
    $user = User::factory()->create();

    $token = (new TokenService())->issue($user);
    $payload = JWTAuth::setToken($token)->getPayload();

    expect($payload->get('tid'))->toBeNull();
    expect((int) $payload->get('sub'))->toBe($user->id);
});

it('issues a token with tid and role when a membership is given', function () {
    $user = User::factory()->create();
    $business = Business::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    $token = (new TokenService())->issue($user, $membership);
    $payload = JWTAuth::setToken($token)->getPayload();

    expect($payload->get('tid'))->toBe($business->id);
    expect($payload->get('role'))->toBe('owner');
});
```

- [x] **Step 5: Run the tests**

Run: `cd backend && php artisan test --filter=TokenServiceTest`
Expected: PASS (2 passed)

- [x] **Step 6: Commit**

```bash
git add backend/config/auth.php backend/app/Services/TokenService.php backend/tests/Unit/TokenServiceTest.php backend/.env.example
git commit -m "feat: configure JWT auth and add TokenService for tid/role claims"
```

---

## Task 8: SetTenantContext + RequireTenant middleware

**Files:**
- Create: `backend/app/Http/Middleware/SetTenantContext.php`
- Create: `backend/app/Http/Middleware/RequireTenant.php`
- Modify: `backend/bootstrap/app.php`
- Create: `backend/routes/api.php` (whoami smoke-test route)
- Test: `backend/tests/Feature/Tenancy/TenantContextMiddlewareTest.php`

This is the mechanism from PRD §4.2/§4.3 translated into Laravel: one transaction per request, `SET LOCAL` for both GUCs, membership verification, and app-container bindings other code reads via `app('tenant.id')`/`app('tenant.role')`/`app('tenant.user_id')`.

- [x] **Step 1: Write SetTenantContext**

```php
<?php
// app/Http/Middleware/SetTenantContext.php

namespace App\Http\Middleware;

use App\Models\Membership;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $payload = JWTAuth::parseToken()->getPayload();
        $userId = (int) $payload->get('sub');
        $tid = $payload->get('tid');
        $role = $payload->get('role');

        // bind(), not instance(): the container resolves instances via
        // isset($this->instances[$abstract]), and isset(null) is false — a null
        // instance falls through to construction and throws
        // "Target class [tenant.id] does not exist".
        app()->bind('tenant.id', fn () => null);
        app()->bind('tenant.role', fn () => null);
        app()->bind('tenant.user_id', fn () => $userId);

        DB::beginTransaction();

        try {
            // set_config(..., true) is SET LOCAL with bind parameters — Postgres
            // rejects placeholders in a bare `SET LOCAL`.
            DB::statement("select set_config('app.current_user_id', ?, true)", [(string) $userId]);

            if ($tid !== null) {
                $isMember = Membership::where('user_id', $userId)
                    ->where('business_id', $tid)
                    ->exists();

                if (! $isMember) {
                    DB::rollBack();

                    return response()->json(['message' => 'Not a member of this business.'], 403);
                }

                DB::statement("select set_config('app.current_tenant', ?, true)", [(string) $tid]);
                app()->bind('tenant.id', fn () => $tid);
                app()->bind('tenant.role', fn () => $role);
            }

            $response = $next($request);
            DB::commit();

            return $response;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
```

The `Membership::where(...)->exists()` check itself runs *inside* the same transaction, after `app.current_user_id` is set but before `app.current_tenant` is — it's allowed through by the RLS policy's `user_id` branch (Task 5), not the `tid` branch, since the tenant GUC isn't set yet at that point.

- [x] **Step 2: Write RequireTenant**

```php
<?php
// app/Http/Middleware/RequireTenant.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app('tenant.id') === null) {
            return response()->json(['message' => 'No active business selected.'], 400);
        }

        return $next($request);
    }
}
```

- [x] **Step 3: Register both middleware aliases**

Edit `backend/bootstrap/app.php`. Laravel 11 does not load `routes/api.php` unless it is registered, so add the `api:` line as well — without it every route in this task 404s:

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
)
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'tenant.context' => \App\Http\Middleware\SetTenantContext::class,
        'require.tenant' => \App\Http\Middleware\RequireTenant::class,
    ]);
})
```

- [x] **Step 4: Add a `whoami` smoke-test route**

```php
<?php
// routes/api.php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware(['auth:api', 'tenant.context'])->group(function () {
        Route::get('whoami', function () {
            return response()->json([
                'user_id' => app('tenant.user_id'),
                'tenant_id' => app('tenant.id'),
                'role' => app('tenant.role'),
            ]);
        });
    });
});
```

- [x] **Step 5: Write a failing feature test**

```php
<?php
// tests/Feature/Tenancy/TenantContextMiddlewareTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\User;
use App\Services\TokenService;

it('resolves tenant id and role from the token for a member', function () {
    $user = User::factory()->create();
    $business = Business::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);
    $token = (new TokenService())->issue($user, $membership);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/whoami')
        ->assertOk()
        ->assertJson([
            'user_id' => $user->id,
            'tenant_id' => $business->id,
            'role' => 'owner',
        ]);
});

it('rejects a token whose tid the user is not a member of', function () {
    $user = User::factory()->create();
    $otherBusiness = Business::factory()->create();
    $token = app(\App\Services\TokenService::class);

    $rawToken = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::claims([
        'tid' => $otherBusiness->id,
        'role' => 'owner',
    ])->fromUser($user);

    $this->withHeader('Authorization', "Bearer {$rawToken}")
        ->getJson('/api/v1/whoami')
        ->assertStatus(403);
});
```

- [x] **Step 6: Run the tests**

Run: `cd backend && php artisan test --filter=TenantContextMiddlewareTest`
Expected: PASS (2 passed)

- [x] **Step 7: Commit**

```bash
git add backend/app/Http/Middleware backend/bootstrap/app.php backend/routes/api.php backend/tests/Feature/Tenancy/TenantContextMiddlewareTest.php
git commit -m "feat: add SetTenantContext/RequireTenant middleware"
```

---

## Task 9: OTP request/verify

**Files:**
- Create: `backend/database/migrations/2026_07_05_000005_create_otp_codes_table.php`
- Create: `backend/app/Models/OtpCode.php`
- Create: `backend/app/Services/OtpService.php`
- Create: `backend/app/Http/Controllers/Api/V1/OtpController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Auth/OtpTest.php`

- [x] **Step 1: Write the migration**

```php
<?php
// database/migrations/2026_07_05_000005_create_otp_codes_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 15);
            $table->string('code_hash', 128);
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('otp_codes');
    }
};
```

No RLS — pre-membership, scoped by `phone` + expiry/attempts at the app layer (per the approved spec).

- [x] **Step 2: Write the OtpCode model**

```php
<?php
// app/Models/OtpCode.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    protected $fillable = ['phone', 'code_hash', 'expires_at', 'attempts', 'consumed_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];
}
```

- [x] **Step 3: Write the OtpService**

```php
<?php
// app/Services/OtpService.php

namespace App\Services;

use App\Models\OtpCode;
use Carbon\Carbon;

class OtpService
{
    private const CODE_TTL_MINUTES = 5;
    private const MAX_ATTEMPTS = 5;

    public function generate(string $phone): string
    {
        $code = (string) random_int(100000, 999999);

        OtpCode::create([
            'phone' => $phone,
            'code_hash' => hash('sha256', $code),
            'expires_at' => Carbon::now()->addMinutes(self::CODE_TTL_MINUTES),
        ]);

        return $code;
    }

    public function verify(string $phone, string $code): bool
    {
        $otp = OtpCode::where('phone', $phone)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', Carbon::now())
            ->latest('id')
            ->first();

        if (! $otp || $otp->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        $otp->increment('attempts');

        if (! hash_equals($otp->code_hash, hash('sha256', $code))) {
            return false;
        }

        $otp->update(['consumed_at' => Carbon::now()]);

        return true;
    }
}
```

- [x] **Step 4: Write the OtpController**

```php
<?php
// app/Http/Controllers/Api/V1/OtpController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use App\Services\TokenService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class OtpController extends Controller
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly TokenService $tokenService,
    ) {}

    public function request(Request $request)
    {
        $data = $request->validate(['phone' => ['required', 'string', 'regex:/^[0-9]{10,15}$/']]);

        $key = 'otp-request:' . $data['phone'];
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json(['message' => 'Too many OTP requests. Try again later.'], 429);
        }
        RateLimiter::hit($key, 3600);

        $code = $this->otpService->generate($data['phone']);
        Log::info("OTP for {$data['phone']}: {$code}");

        $response = ['message' => 'OTP sent.'];
        if (app()->environment(['local', 'testing'])) {
            $response['debug_code'] = $code;
        }

        return response()->json($response);
    }

    public function verify(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        if (! $this->otpService->verify($data['phone'], $data['code'])) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $user = User::firstOrCreate(
            ['phone' => $data['phone']],
            ['name' => $data['phone'], 'password' => bcrypt(str()->random(32))]
        );

        $membership = TenantContext::forUser(
            $user->id,
            fn () => $user->memberships()->count() === 1 ? $user->memberships()->first() : null
        );

        return response()->json(['token' => $this->tokenService->issue($user, $membership)]);
    }
}
```

- [x] **Step 5: Add the routes**

Add to `backend/routes/api.php`, above the authenticated group:

```php
Route::post('auth/otp/request', [\App\Http\Controllers\Api\V1\OtpController::class, 'request']);
Route::post('auth/otp/verify', [\App\Http\Controllers\Api\V1\OtpController::class, 'verify']);
```

(Wrap these, along with the existing authenticated group, inside the `Route::prefix('v1')->group(...)` block already present.)

- [x] **Step 6: Write failing feature tests**

```php
<?php
// tests/Feature/Auth/OtpTest.php

it('issues a token after verifying a correct otp', function () {
    $phone = '9876543210';

    $requestResponse = $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone])->assertOk();
    $code = $requestResponse->json('debug_code');

    $this->postJson('/api/v1/auth/otp/verify', ['phone' => $phone, 'code' => $code])
        ->assertOk()
        ->assertJsonStructure(['token']);
});

it('rejects an incorrect otp', function () {
    $phone = '9876543211';
    $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone]);

    $this->postJson('/api/v1/auth/otp/verify', ['phone' => $phone, 'code' => '000000'])
        ->assertStatus(422);
});

it('rate limits repeated otp requests for the same phone', function () {
    $phone = '9876543212';

    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone])->assertOk();
    }

    $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone])->assertStatus(429);
});
```

- [x] **Step 7: Run the migration and tests**

```bash
cd backend
php artisan migrate --database=pgsql_migrate
php artisan test --filter=OtpTest
```
Expected: PASS (3 passed)

- [x] **Step 8: Commit**

```bash
git add backend/database backend/app/Models/OtpCode.php backend/app/Services/OtpService.php backend/app/Http/Controllers/Api/V1/OtpController.php backend/routes/api.php backend/tests/Feature/Auth/OtpTest.php
git commit -m "feat: add phone OTP request/verify endpoints"
```

---

## Task 10: Email/password register + login

**Files:**
- Create: `backend/app/Http/Controllers/Api/V1/AuthController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Auth/RegisterLoginTest.php`

- [x] **Step 1: Write the AuthController**

```php
<?php
// app/Http/Controllers/Api/V1/AuthController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TokenService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(private readonly TokenService $tokenService) {}

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        return response()->json(['token' => $this->tokenService->issue($user)], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $membership = TenantContext::forUser(
            $user->id,
            fn () => $user->memberships()->count() === 1 ? $user->memberships()->first() : null
        );

        return response()->json(['token' => $this->tokenService->issue($user, $membership)]);
    }
}
```

Both `login` here and `otp/verify` in Task 9 resolve memberships through `TenantContext::forUser()`. These are public routes, so `SetTenantContext` never runs and `app.current_user_id` is never set — and without it the memberships RLS policy hides *every* row. `$user->memberships()->count()` would always be `0`, the single-membership auto-select would silently never fire, and every user would get a tenant-less token while the code reads as though it works. This is the failure mode the policy's `user_id` branch exists to prevent; it just has to be switched on.

- [x] **Step 2: Add the routes**

Add to `backend/routes/api.php`, alongside the OTP routes:

```php
Route::post('auth/register', [\App\Http\Controllers\Api\V1\AuthController::class, 'register']);
Route::post('auth/login', [\App\Http\Controllers\Api\V1\AuthController::class, 'login']);
```

- [x] **Step 3: Write failing feature tests**

```php
<?php
// tests/Feature/Auth/RegisterLoginTest.php

it('registers a new user and issues a token', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Vijay Kumar',
        'email' => 'vijay@example.com',
        'password' => 'password123',
    ])->assertCreated()->assertJsonStructure(['token']);
});

it('rejects login with the wrong password', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Vijay Kumar',
        'email' => 'vijay2@example.com',
        'password' => 'password123',
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'vijay2@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(401);
});

it('logs in with the correct password', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Vijay Kumar',
        'email' => 'vijay3@example.com',
        'password' => 'password123',
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'vijay3@example.com',
        'password' => 'password123',
    ])->assertOk()->assertJsonStructure(['token']);
});
```

- [x] **Step 4: Run the tests**

Run: `cd backend && php artisan test --filter=RegisterLoginTest`
Expected: PASS (3 passed)

- [x] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/Api/V1/AuthController.php backend/routes/api.php backend/tests/Feature/Auth/RegisterLoginTest.php
git commit -m "feat: add email/password register and login endpoints"
```

---

## Task 11: Create-business endpoint

**Files:**
- Create: `backend/app/Http/Controllers/Api/V1/BusinessController.php` (store method only — mine/switch added in Task 12)
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Business/CreateBusinessTest.php`

- [x] **Step 1: Write the BusinessController's store method**

```php
<?php
// app/Http/Controllers/Api/V1/BusinessController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Membership;
use App\Services\TokenService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessController extends Controller
{
    public function __construct(private readonly TokenService $tokenService) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:80'],
            'gstin' => ['nullable', 'string', 'max:15'],
            'default_language' => ['nullable', 'string', 'max:8'],
        ]);

        $userId = app('tenant.user_id');

        $membership = DB::transaction(function () use ($data, $userId) {
            $business = Business::create($data);

            TenantContext::switchTo($business->id);

            return Membership::create([
                'user_id' => $userId,
                'business_id' => $business->id,
                'role' => 'owner',
            ]);
        });

        $user = \App\Models\User::find($userId);

        return response()->json([
            'business' => $membership->business,
            'token' => $this->tokenService->issue($user, $membership),
        ], 201);
    }
}
```

The nested `DB::transaction` here runs inside the outer transaction `SetTenantContext` already opened (Laravel/Postgres handle this via a savepoint), and `TenantContext::switchTo()` re-points `app.current_tenant` to the newly created business right before the `Membership` insert — satisfying the RLS `WITH CHECK` even though the caller's JWT `tid` (if any) points at a different business entirely.

- [x] **Step 2: Add the route**

Add inside the `Route::middleware(['auth:api', 'tenant.context'])->group(...)` block in `backend/routes/api.php`:

```php
Route::post('businesses', [\App\Http\Controllers\Api\V1\BusinessController::class, 'store']);
```

- [x] **Step 3: Write a failing feature test**

```php
<?php
// tests/Feature/Business/CreateBusinessTest.php

use App\Models\User;
use App\Services\TokenService;

it('creates a business and returns a token scoped to it as owner', function () {
    $user = User::factory()->create();
    $token = (new TokenService())->issue($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/businesses', ['name' => 'Shree Raj Shyama Ji Namkeen', 'city' => 'Hata'])
        ->assertCreated();

    $businessId = $response->json('business.id');
    expect($businessId)->not->toBeNull();

    $newToken = $response->json('token');
    $payload = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::setToken($newToken)->getPayload();
    expect($payload->get('tid'))->toBe($businessId);
    expect($payload->get('role'))->toBe('owner');
});

it('lets a user create a second business while already owning one', function () {
    $user = User::factory()->create();
    $firstToken = (new TokenService())->issue($user);

    $first = $this->withHeader('Authorization', "Bearer {$firstToken}")
        ->postJson('/api/v1/businesses', ['name' => 'First Shop'])
        ->assertCreated();

    // Authenticate using a token scoped to the FIRST business, then create a second.
    $tokenScopedToFirst = $first->json('token');

    $second = $this->withHeader('Authorization', "Bearer {$tokenScopedToFirst}")
        ->postJson('/api/v1/businesses', ['name' => 'Second Shop'])
        ->assertCreated();

    expect($second->json('business.id'))->not->toBe($first->json('business.id'));
});
```

**Both** tests exercise `TenantContext::switchTo()` — verified by deleting the call and watching each fail with `new row violates row-level security policy for table "memberships"`. The second is the more obvious case: the request's active `tid` (the first business) differs from the new membership's `business_id` (the second), so `WITH CHECK` compares two different UUIDs and rejects. But the first fails too, for a subtler reason: a tenant-less token leaves `app.current_tenant` unset, so `WITH CHECK` evaluates `business_id = NULL`, which is `NULL` rather than `true` — and a check constraint only admits a row on `true`. Creating your very first business is just as dependent on `switchTo()` as creating your fifth.

- [x] **Step 4: Run the tests**

Run: `cd backend && php artisan test --filter=CreateBusinessTest`
Expected: PASS (2 passed)

- [x] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/Api/V1/BusinessController.php backend/routes/api.php backend/tests/Feature/Business/CreateBusinessTest.php
git commit -m "feat: add business creation endpoint"
```

---

## Task 12: Businesses mine + switch

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/BusinessController.php` (add `mine`, `switch`)
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Business/SwitchBusinessTest.php`

- [x] **Step 1: Add `mine` and `switch` to BusinessController**

```php
// app/Http/Controllers/Api/V1/BusinessController.php — add these methods to the existing class:

public function mine(Request $request)
{
    $memberships = \App\Models\Membership::with('business')
        ->where('user_id', app('tenant.user_id'))
        ->get();

    return response()->json($memberships->map(fn ($m) => [
        'business' => $m->business,
        'role' => $m->role,
    ]));
}

public function switch(Request $request, string $id)
{
    $membership = \App\Models\Membership::where('user_id', app('tenant.user_id'))
        ->where('business_id', $id)
        ->first();

    if (! $membership) {
        return response()->json(['message' => 'Not a member of this business.'], 403);
    }

    $user = \App\Models\User::find(app('tenant.user_id'));

    return response()->json(['token' => $this->tokenService->issue($user, $membership)]);
}
```

- [x] **Step 2: Add the routes**

Add inside the `Route::middleware(['auth:api', 'tenant.context'])->group(...)` block:

```php
Route::get('businesses/mine', [\App\Http\Controllers\Api\V1\BusinessController::class, 'mine']);
Route::post('businesses/{id}/switch', [\App\Http\Controllers\Api\V1\BusinessController::class, 'switch']);
```

- [x] **Step 3: Write failing feature tests**

```php
<?php
// tests/Feature/Business/SwitchBusinessTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\User;
use App\Services\TokenService;

it('lists every business the user belongs to', function () {
    $user = User::factory()->create();
    $businessA = Business::factory()->create(['name' => 'Shop A']);
    $businessB = Business::factory()->create(['name' => 'Shop B']);
    Membership::on('pgsql_migrate')->create(['user_id' => $user->id, 'business_id' => $businessA->id, 'role' => 'owner']);
    Membership::on('pgsql_migrate')->create(['user_id' => $user->id, 'business_id' => $businessB->id, 'role' => 'salesman']);

    $token = (new TokenService())->issue($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/businesses/mine')
        ->assertOk();

    expect($response->json())->toHaveCount(2);
});

it('switches to a business the user is a member of', function () {
    $user = User::factory()->create();
    $businessA = Business::factory()->create();
    $businessB = Business::factory()->create();
    $membershipA = Membership::on('pgsql_migrate')->create(['user_id' => $user->id, 'business_id' => $businessA->id, 'role' => 'owner']);
    Membership::on('pgsql_migrate')->create(['user_id' => $user->id, 'business_id' => $businessB->id, 'role' => 'salesman']);

    $tokenForA = (new TokenService())->issue($user, $membershipA);

    $response = $this->withHeader('Authorization', "Bearer {$tokenForA}")
        ->postJson("/api/v1/businesses/{$businessB->id}/switch")
        ->assertOk();

    $payload = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::setToken($response->json('token'))->getPayload();
    expect($payload->get('tid'))->toBe($businessB->id);
    expect($payload->get('role'))->toBe('salesman');
});

it('rejects switching to a business the user is not a member of', function () {
    $user = User::factory()->create();
    $notMyBusiness = Business::factory()->create();
    $token = (new TokenService())->issue($user);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/businesses/{$notMyBusiness->id}/switch")
        ->assertStatus(403);
});

it('rejects switching into a business that someone else is a member of', function () {
    // The business above has no memberships at all, so that test passes even
    // with the authorization check deleted — nothing exists to find. Here a
    // membership row genuinely exists and belongs to a stranger, which is the
    // row a broken query would hand back.
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $strangersBusiness = Business::factory()->create();
    Membership::on('pgsql_migrate')->create([
        'user_id' => $stranger->id,
        'business_id' => $strangersBusiness->id,
        'role' => 'owner',
    ]);

    $token = (new TokenService())->issue($user);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/businesses/{$strangersBusiness->id}/switch")
        ->assertStatus(403);
});
```

The fourth test exists because the third proves nothing on its own: `$notMyBusiness` has no memberships, so the query finds nothing whether or not the authorization check is present — deleting `->where('user_id', ...)` from `switch()` leaves it passing. The fourth puts a real membership row in the way.

Worth knowing what that fourth test does and does not pin down: with the app-level `user_id` check deleted it *still* returns 403, because RLS independently hides the stranger's membership (the caller's token carries no `tid`, so the policy's `business_id = current_tenant` branch is NULL and the `user_id` branch does not match). That is defense in depth behaving exactly as designed — the DB layer alone is sufficient — and it means no HTTP-level test can isolate the app-layer check while RLS is healthy. The test guards the case where *both* layers regress.

- [x] **Step 4: Run the tests**

Run: `cd backend && php artisan test --filter=SwitchBusinessTest`
Expected: PASS (4 passed)

- [x] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/Api/V1/BusinessController.php backend/routes/api.php backend/tests/Feature/Business/SwitchBusinessTest.php
git commit -m "feat: add businesses/mine and business switch endpoints"
```

---

## Task 13: Invite model, migration, and endpoints

**Files:**
- Create: `backend/database/migrations/2026_07_05_000006_create_invites_table.php`
- Create: `backend/app/Models/Invite.php`
- Create: `backend/app/Policies/InvitePolicy.php`
- Create: `backend/app/Http/Controllers/Api/V1/InviteController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Invite/InviteTest.php`

(No `AppServiceProvider` change: `InvitePolicy` reads `app('tenant.role')` and takes neither a `User` nor a model, so it is a role-check helper the controller calls directly, not a Gate policy there would be anything to register.)

- [x] **Step 1: Write the migration**

```php
<?php
// database/migrations/2026_07_05_000006_create_invites_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('invites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->enum('role', ['owner', 'admin', 'salesman', 'accountant'])->default('salesman');
            $table->string('token', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->foreignId('redeemed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('invites');
    }
};
```

No RLS — same reasoning as `otp_codes`: pre-membership, scoped by the unguessable `token` + expiry/redemption state at the app layer.

- [x] **Step 2: Write the Invite model**

```php
<?php
// app/Models/Invite.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Invite extends Model
{
    use HasUuids;

    protected $fillable = ['business_id', 'role', 'token', 'invited_by', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'redeemed_at' => 'datetime',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
```

- [x] **Step 3: Write the InvitePolicy**

```php
<?php
// app/Policies/InvitePolicy.php

namespace App\Policies;

class InvitePolicy
{
    public function create(): bool
    {
        return in_array(app('tenant.role'), ['owner', 'admin'], true);
    }
}
```

- [x] **Step 4: Write the InviteController**

```php
<?php
// app/Http/Controllers/Api/V1/InviteController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invite;
use App\Models\Membership;
use App\Models\User;
use App\Policies\InvitePolicy;
use App\Services\TokenService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InviteController extends Controller
{
    public function __construct(private readonly TokenService $tokenService) {}

    public function store(Request $request)
    {
        if (! (new InvitePolicy())->create()) {
            return response()->json(['message' => 'Only owners and admins can invite staff.'], 403);
        }

        $data = $request->validate([
            'role' => ['nullable', 'in:owner,admin,salesman,accountant'],
        ]);

        $invite = Invite::create([
            'business_id' => app('tenant.id'),
            'role' => $data['role'] ?? 'salesman',
            'token' => Str::random(48),
            'invited_by' => app('tenant.user_id'),
            'expires_at' => Carbon::now()->addDays(7),
        ]);

        return response()->json([
            'invite_link' => '/invite/accept?token=' . $invite->token,
        ], 201);
    }

    public function accept(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string']]);

        $invite = Invite::where('token', $data['token'])
            ->whereNull('redeemed_at')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (! $invite) {
            return response()->json(['message' => 'Invalid or expired invite.'], 422);
        }

        $userId = app('tenant.user_id');

        // Invite links get shared in group chats, so an existing member tapping
        // one is routine. Without this the memberships unique index raises and
        // the request 500s. The invite is deliberately left unredeemed so the
        // person it was actually meant for can still use it.
        $alreadyMember = Membership::where('user_id', $userId)
            ->where('business_id', $invite->business_id)
            ->exists();

        if ($alreadyMember) {
            return response()->json(['message' => 'Already a member of this business.'], 409);
        }

        $membership = DB::transaction(function () use ($invite, $userId) {
            TenantContext::switchTo($invite->business_id);

            $membership = Membership::create([
                'user_id' => $userId,
                'business_id' => $invite->business_id,
                'role' => $invite->role,
            ]);

            // Assigned directly rather than via update(): redeemed_by/redeemed_at
            // are not in the model's $fillable (and must not be — they are never
            // client-supplied), so mass assignment silently drops them, leaving
            // the invite redeemable by anyone holding the link until it expires.
            $invite->redeemed_by = $userId;
            $invite->redeemed_at = Carbon::now();
            $invite->save();

            return $membership;
        });

        $user = User::find($userId);

        return response()->json(['token' => $this->tokenService->issue($user, $membership)]);
    }
}
```

- [x] **Step 5: Add the routes**

Add `invites/accept` inside the `['auth:api', 'tenant.context']` group, and `businesses/{id}/invite` inside a nested `['require.tenant']` group:

```php
Route::post('invites/accept', [\App\Http\Controllers\Api\V1\InviteController::class, 'accept']);

Route::middleware(['require.tenant'])->group(function () {
    Route::post('businesses/{id}/invite', [\App\Http\Controllers\Api\V1\InviteController::class, 'store']);
});
```

- [x] **Step 6: Write failing feature tests**

```php
<?php
// tests/Feature/Invite/InviteTest.php

use App\Models\Business;
use App\Models\Invite;
use App\Models\Membership;
use App\Models\User;
use App\Services\TokenService;

it('lets an owner create an invite', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create(['user_id' => $owner->id, 'business_id' => $business->id, 'role' => 'owner']);
    $token = (new TokenService())->issue($owner, $membership);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/businesses/{$business->id}/invite", ['role' => 'salesman'])
        ->assertCreated()
        ->assertJsonStructure(['invite_link']);
});

it('blocks a salesman from creating an invite', function () {
    $salesman = User::factory()->create();
    $business = Business::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create(['user_id' => $salesman->id, 'business_id' => $business->id, 'role' => 'salesman']);
    $token = (new TokenService())->issue($salesman, $membership);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/businesses/{$business->id}/invite", ['role' => 'salesman'])
        ->assertStatus(403);
});

it('lets a new user accept an invite and become a member', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->create();
    $ownerMembership = Membership::on('pgsql_migrate')->create(['user_id' => $owner->id, 'business_id' => $business->id, 'role' => 'owner']);
    $ownerToken = (new TokenService())->issue($owner, $ownerMembership);

    $inviteResponse = $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson("/api/v1/businesses/{$business->id}/invite", ['role' => 'salesman'])
        ->assertCreated();

    $inviteToken = str($inviteResponse->json('invite_link'))->after('token=')->toString();

    $newUser = User::factory()->create();
    $newUserToken = (new TokenService())->issue($newUser);

    $acceptResponse = $this->withHeader('Authorization', "Bearer {$newUserToken}")
        ->postJson('/api/v1/invites/accept', ['token' => $inviteToken])
        ->assertOk();

    $payload = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::setToken($acceptResponse->json('token'))->getPayload();
    expect($payload->get('tid'))->toBe($business->id);
    expect($payload->get('role'))->toBe('salesman');
});

it('rejects accepting an invite for a business the user already belongs to', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->create();
    $ownerMembership = Membership::on('pgsql_migrate')->create(['user_id' => $owner->id, 'business_id' => $business->id, 'role' => 'owner']);
    $ownerToken = (new TokenService())->issue($owner, $ownerMembership);

    $inviteResponse = $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson("/api/v1/businesses/{$business->id}/invite", ['role' => 'salesman'])
        ->assertCreated();

    $inviteToken = str($inviteResponse->json('invite_link'))->after('token=')->toString();

    $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson('/api/v1/invites/accept', ['token' => $inviteToken])
        ->assertStatus(409);

    // The invite must survive unredeemed so the person it was meant for can use it.
    expect(Invite::on('pgsql_migrate')->where('token', $inviteToken)->first()->redeemed_at)->toBeNull();
});

it('marks an invite redeemed so a second person cannot reuse the link', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->create();
    $ownerMembership = Membership::on('pgsql_migrate')->create(['user_id' => $owner->id, 'business_id' => $business->id, 'role' => 'owner']);
    $ownerToken = (new TokenService())->issue($owner, $ownerMembership);

    $inviteResponse = $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson("/api/v1/businesses/{$business->id}/invite", ['role' => 'salesman'])
        ->assertCreated();

    $inviteToken = str($inviteResponse->json('invite_link'))->after('token=')->toString();

    $firstUser = User::factory()->create();
    $this->withHeader('Authorization', 'Bearer ' . (new TokenService())->issue($firstUser))
        ->postJson('/api/v1/invites/accept', ['token' => $inviteToken])
        ->assertOk();

    $invite = Invite::on('pgsql_migrate')->where('token', $inviteToken)->first();
    expect($invite->redeemed_at)->not->toBeNull();
    expect($invite->redeemed_by)->toBe($firstUser->id);

    // An invite is single-use. A second person holding the same link — forwarded
    // from a group chat, say — must not be able to join the business with it.
    $secondUser = User::factory()->create();
    $this->withHeader('Authorization', 'Bearer ' . (new TokenService())->issue($secondUser))
        ->postJson('/api/v1/invites/accept', ['token' => $inviteToken])
        ->assertStatus(422);

    expect(Membership::on('pgsql_migrate')->where('user_id', $secondUser->id)->exists())->toBeFalse();
});
```

The last two tests cover bugs the first three do not reach, both found by driving the endpoints for real rather than by reading the code:

- **Accepting an invite for a business you already belong to returned HTTP 500** — the `memberships` unique index raised and nothing caught it. Routine in practice: invite links get forwarded, and existing members tap them.
- **Invites were never marked redeemed, so a single link could be redeemed by unlimited people.** `redeemed_by`/`redeemed_at` are absent from `Invite::$fillable`, so `$invite->update([...])` was silently a no-op. The first three tests all pass with this bug present — none of them checks redemption state or tries to reuse a link. `invites` carries no RLS, so nothing at the database layer backstops it either; the token is the only thing standing between a forwarded link and a stranger joining the business.

- [x] **Step 7: Run the migration and tests**

```bash
cd backend
php artisan migrate --database=pgsql_migrate
php artisan test --filter=InviteTest
```
Expected: PASS (5 passed)

- [x] **Step 8: Commit**

```bash
git add backend/database backend/app/Models/Invite.php backend/app/Policies backend/app/Http/Controllers/Api/V1/InviteController.php backend/routes/api.php backend/tests/Feature/Invite/InviteTest.php
git commit -m "feat: add staff invite creation and acceptance endpoints"
```

---

## Task 14: TenantAwareJob trait

**Files:**
- Create: `backend/app/Traits/TenantAwareJob.php`
- Test: `backend/tests/Unit/TenantAwareJobTest.php`

No real queued job exists yet in this slice, so this is proven with a minimal fixture job in the test, ready for future slices (e.g., sending a WhatsApp reminder) to adopt.

- [ ] **Step 1: Write the trait**

```php
<?php
// app/Traits/TenantAwareJob.php

namespace App\Traits;

use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

trait TenantAwareJob
{
    public string $tenantId;

    public function withTenant(string $tenantId): static
    {
        $this->tenantId = $tenantId;

        return $this;
    }

    public function handle(): void
    {
        DB::transaction(function () {
            TenantContext::switchTo($this->tenantId);
            $this->handleForTenant();
        });
    }

    abstract public function handleForTenant(): void;
}
```

Set the GUC via `TenantContext::switchTo()`, never `DB::statement('SET LOCAL app.current_tenant = ?', [...])`. Postgres's `SET` grammar does not accept a bind parameter in the value position — that statement fails with `syntax error at or near "$1"` on every connection. `switchTo()` wraps `set_config(name, value, true)`, which is parameterizable and has identical `SET LOCAL` (transaction-scoped) semantics.

- [ ] **Step 2: Write a failing unit test with a fixture job**

```php
<?php
// tests/Unit/TenantAwareJobTest.php

use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

class FixtureTenantJob implements ShouldQueue
{
    use Dispatchable, Queueable, TenantAwareJob;

    public static ?string $observedTenant = null;

    public function handleForTenant(): void
    {
        self::$observedTenant = DB::selectOne("select current_setting('app.current_tenant', true) as t")->t;
    }
}

it('sets the tenant GUC before running the job body', function () {
    FixtureTenantJob::$observedTenant = null;

    $job = (new FixtureTenantJob())->withTenant('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
    $job->handle();

    expect(FixtureTenantJob::$observedTenant)->toBe('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
});
```

- [ ] **Step 3: Run the test**

Run: `cd backend && php artisan test --filter=TenantAwareJobTest`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add backend/app/Traits/TenantAwareJob.php backend/tests/Unit/TenantAwareJobTest.php
git commit -m "feat: add TenantAwareJob trait for queue tenant-context propagation"
```

---

## Task 15: Cross-tenant-leak test suite

**Files:**
- Create: `backend/tests/Feature/Tenancy/CrossTenantLeakTest.php`

This suite deliberately tries to leak data between two tenants through the real HTTP API and must fail to do so — the explicit risk mitigation from PRD §19.

- [ ] **Step 1: Write the test suite**

```php
<?php
// tests/Feature/Tenancy/CrossTenantLeakTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\User;
use App\Services\TokenService;

function ownerContext(): array
{
    $owner = User::factory()->create();
    $business = Business::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    return [$owner, $business, (new TokenService())->issue($owner, $membership)];
}

it('never returns business Bs memberships in business As mine listing', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/businesses/mine')
        ->assertOk();

    $businessIds = collect($response->json())->pluck('business.id');

    expect($businessIds)->toContain($businessA->id);
    expect($businessIds)->not->toContain($businessB->id);
});

it('rejects business As owner switching into business B', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson("/api/v1/businesses/{$businessB->id}/switch")
        ->assertStatus(403);
});

it('rejects a token forged with another tenants tid without a matching membership', function () {
    [$ownerA, $businessA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    $forgedToken = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::claims([
        'tid' => $businessB->id,
        'role' => 'owner',
    ])->fromUser($ownerA);

    $this->withHeader('Authorization', "Bearer {$forgedToken}")
        ->getJson('/api/v1/whoami')
        ->assertStatus(403);
});

it('rejects business As owner inviting staff into business B via a mismatched path id', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    // Owner A's token is scoped to business A; RequireTenant only confirms *a* tenant
    // is active, so the controller itself must not trust the {id} path segment blindly.
    // Since invite's business_id always comes from app('tenant.id'), not the path param,
    // this proves the invite is created for A, never for the B id in the URL.
    $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson("/api/v1/businesses/{$businessB->id}/invite", ['role' => 'salesman'])
        ->assertCreated();

    // latest('created_at'), not latest('id'): Invite uses HasUuids, so ordering
    // by id sorts UUIDs lexically rather than chronologically.
    $invite = \App\Models\Invite::latest('created_at')->first();
    expect($invite->business_id)->toBe($businessA->id);
    expect($invite->business_id)->not->toBe($businessB->id);
});

it('never lets accepting an expired invite create a membership', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();

    $invite = \App\Models\Invite::create([
        'business_id' => $businessA->id,
        'role' => 'salesman',
        'token' => 'expired-token-123',
        'invited_by' => $ownerA->id,
        'expires_at' => now()->subDay(),
    ]);

    $newUser = User::factory()->create();
    $newUserToken = (new TokenService())->issue($newUser);

    $this->withHeader('Authorization', "Bearer {$newUserToken}")
        ->postJson('/api/v1/invites/accept', ['token' => 'expired-token-123'])
        ->assertStatus(422);

    expect(Membership::on('pgsql_migrate')->where('user_id', $newUser->id)->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run the tests**

Run: `cd backend && php artisan test --filter=CrossTenantLeakTest`
Expected: PASS (5 passed)

- [ ] **Step 3: Commit**

```bash
git add backend/tests/Feature/Tenancy/CrossTenantLeakTest.php
git commit -m "test: add cross-tenant leak suite"
```

---

## Task 16: PgBouncer pooled-connection isolation test

**Files:**
- Create: `backend/tests/Feature/Tenancy/PgBouncerPooledConnectionTest.php`

This targets the failure mode PRD §4.2 warns about: two sequential requests reusing a pooled connection must never see each other's `SET LOCAL` value, because `SET LOCAL` is transaction-scoped and cleared on commit.

**Be precise about what this test does and does not prove.** It runs in-process through Laravel's test HTTP layer, so it does not put PgBouncer in the path and does not prove PgBouncer is configured correctly. What it proves is the *property* that PgBouncer safety depends on: that the tenant GUC does not survive its transaction as session state on a reused connection. That is worth proving — if it ever regressed (e.g. someone "fixes" a bug by switching `SET LOCAL` to a session-scoped `SET`), transaction pooling would leak tenant context between unrelated requests. But it is a Postgres-semantics test, not an integration test.

Two things are required for this to mean anything in a real deployment, neither of which the test can enforce:

- `DB_PORT` must point at PgBouncer (6432), not directly at Postgres (5432). If the app connects straight to Postgres, PgBouncer is not exercised anywhere in the suite and this test's name is misleading.
- PgBouncer must run with `pool_mode = transaction` (see Task 17).

A genuine integration test would need the app connection routed through PgBouncer and enough concurrent requests to force server-connection reuse. Consider that a follow-up rather than something this task delivers.

- [ ] **Step 1: Write the test**

```php
<?php
// tests/Feature/Tenancy/PgBouncerPooledConnectionTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Facades\DB;

it('does not leak app.current_tenant across sequential requests on the same connection', function () {
    [$ownerA, $businessA, $tokenA] = (function () {
        $owner = User::factory()->create();
        $business = Business::factory()->create();
        $membership = Membership::on('pgsql_migrate')->create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'role' => 'owner',
        ]);
        return [$owner, $business, (new TokenService())->issue($owner, $membership)];
    })();

    [$ownerB, $businessB, $tokenB] = (function () {
        $owner = User::factory()->create();
        $business = Business::factory()->create();
        $membership = Membership::on('pgsql_migrate')->create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'role' => 'owner',
        ]);
        return [$owner, $business, (new TokenService())->issue($owner, $membership)];
    })();

    // Request 1: tenant A. Middleware commits at the end of the request, which
    // is exactly the point PgBouncer would return the server connection to its pool.
    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/whoami')
        ->assertOk()
        ->assertJson(['tenant_id' => $businessA->id]);

    // Immediately after commit, confirm the GUC has actually cleared on this
    // connection — proving SET LOCAL's scope ended with the transaction and did
    // not silently persist as session state (which is what would leak under PgBouncer).
    //
    // toBeEmpty(), not toBeNull(): once a custom GUC has been set in a session,
    // ending the transaction reverts it to '' rather than NULL. This is exactly
    // why every RLS policy wraps it as NULLIF(current_setting(...), '') — an
    // assertion of toBeNull() here fails against real Postgres.
    $leftover = DB::selectOne("select current_setting('app.current_tenant', true) as t")->t;
    expect($leftover)->toBeEmpty();

    // Request 2: tenant B, reusing the same underlying connection/process.
    $this->withHeader('Authorization', "Bearer {$tokenB}")
        ->getJson('/api/v1/whoami')
        ->assertOk()
        ->assertJson(['tenant_id' => $businessB->id]);
});
```

- [ ] **Step 2: Run the test**

Run: `cd backend && php artisan test --filter=PgBouncerPooledConnectionTest`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add backend/tests/Feature/Tenancy/PgBouncerPooledConnectionTest.php
git commit -m "test: confirm SET LOCAL tenant GUC clears between pooled requests"
```

---

## Task 17: README setup docs

**Files:**
- Create: `backend/README.md`

- [ ] **Step 1: Write the README**

```markdown
# VyaparBook Backend

Laravel 11 API for VyaparBook's tenancy & auth core. No Docker — Postgres,
PgBouncer, and Redis run as native local services.

## Prerequisites

- PHP 8.3, Composer
- PostgreSQL 15+
- PgBouncer
- Redis

## One-time Postgres setup

1. Create the database and a privileged superuser role for migrations (or use
   your existing `postgres` superuser):
   ```sql
   CREATE DATABASE vyaparbook;
   ```
2. After running migrations once (`php artisan migrate --database=pgsql_migrate`,
   see below), the `vyaparbook_app` role will exist but have no password set —
   the migration creates the role but deliberately never sets a password, so no
   secret is embedded in migration history. Until you set one, the app cannot
   connect and every request fails with `password authentication failed for user
   "vyaparbook_app"`. Set it to match your `.env`:
   ```sql
   ALTER ROLE vyaparbook_app WITH PASSWORD 'change-me';
   ```
3. Create the test database. `phpunit.xml` points the suite at it so that running
   tests never wipes your development data:
   ```sql
   CREATE DATABASE vyaparbook_test;
   ```
   No grants are needed by hand — the `create_app_role` migration grants against
   whichever database it runs on, and the test suite migrates this one on its
   first run.

## PgBouncer setup

In `pgbouncer.ini`:
```ini
[databases]
vyaparbook = host=127.0.0.1 port=5432 dbname=vyaparbook

[pgbouncer]
pool_mode = transaction
listen_port = 6432
auth_type = md5
auth_file = userlist.txt
```
`pool_mode = transaction` is required — this project's tenant isolation relies on
`SET LOCAL` inside one transaction per request, which only works correctly under
transaction pooling (see `docs/superpowers/specs/2026-07-04-tenancy-auth-core-design.md` §4).

## App setup

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
# Fill in DB_* and DB_MIGRATE_* in .env to match your Postgres/PgBouncer setup.
# DB_PORT must be PgBouncer's port (6432), not Postgres's (5432) — pointing it at
# 5432 bypasses PgBouncer entirely, so nothing in the app or the test suite ever
# exercises transaction pooling and the Task 16 test proves less than it appears to.
# DB_MIGRATE_PORT is the one that goes direct to Postgres (5432).
php artisan migrate --database=pgsql_migrate
php artisan serve
```

In a separate terminal, run the queue worker:
```bash
cd backend
php artisan queue:work
```

## Running tests

```bash
cd backend
php artisan test
```

## Notes

- All schema migrations run against the `pgsql_migrate` connection (a privileged
  role, direct to Postgres) — the app's runtime connection (`pgsql`, through
  PgBouncer, as the restricted `vyaparbook_app` role) has no DDL rights by design.
- Every tenant-scoped table is protected by Postgres Row-Level Security *and* an
  app-level scope (defense in depth) — see `app/Traits/BelongsToTenant.php`.
```

- [ ] **Step 2: Commit**

```bash
git add backend/README.md
git commit -m "docs: add backend setup instructions"
```

---

## Self-Review Notes

- **Spec coverage:** every §-numbered item in the design spec (data model, RLS policy, `SET LOCAL` propagation, OTP/email auth, business/membership/invite endpoints, `/api/v1` prefix, cross-tenant test suite, PgBouncer test, no-Docker native setup) maps to a task above.
- **Correction from the approved spec:** the spec said `BelongsToTenant` applies to Membership; Task 5/6 above instead keep Membership on a bespoke policy and reserve `BelongsToTenant` for future flat-scoped domain models, because a strict tenant-only scope on Membership would break `/businesses/mine`'s cross-tenant visibility. This is called out explicitly in Task 5 and Task 6 rather than silently diverging.
- **Type/name consistency check:** `TokenService::issue()`, `TenantContext::switchTo()`, `app('tenant.id')` / `app('tenant.role')` / `app('tenant.user_id')`, and the `SetTenantContext` / `RequireTenant` middleware aliases (`tenant.context`, `require.tenant`) are used identically across every task that references them.

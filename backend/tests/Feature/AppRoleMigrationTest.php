<?php
// tests/Feature/AppRoleMigrationTest.php

use Illuminate\Support\Facades\DB;

it('creates a non-superuser vyaparbook_app role', function () {
    $role = DB::connection('pgsql_migrate')
        ->selectOne('select rolname, rolsuper from pg_roles where rolname = ?', ['vyaparbook_app']);

    expect($role)->not->toBeNull();
    expect((bool) $role->rolsuper)->toBeFalse();
});

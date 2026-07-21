<?php
// tests/Unit/PlatformAuditTest.php

use App\Models\Business;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Platform\PlatformAudit;

it('records a mutation with the acting admin, action, target and metadata', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $business = Business::factory()->create();

    $this->actingAs($admin, 'api');

    $log = PlatformAudit::record('suspend', $business->id, ['x' => 1]);

    $stored = PlatformAuditLog::on('pgsql_migrate')->find($log->id);
    expect($stored)->not->toBeNull();
    expect($stored->admin_user_id)->toBe($admin->id);
    expect($stored->action)->toBe('suspend');
    expect($stored->target_business_id)->toBe($business->id);
    expect($stored->metadata['x'])->toBe(1);
    expect($stored->created_at)->not->toBeNull();
});

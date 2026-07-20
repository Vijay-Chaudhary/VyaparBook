<?php
// app/Platform/PlatformAudit.php

namespace App\Platform;

use App\Models\PlatformAuditLog;

/**
 * Writes the platform's mutation trail. The actor is read from auth()->id():
 * platform routes run auth:api but NOT tenant.context (they carry no tenant),
 * so app('tenant.user_id') is unavailable there — the authenticated api guard is.
 *
 * On an operator command (tenant:export, tenant:erase) there is no logged-in
 * admin and admin_user_id is null; those callers pass 'via' => 'cli' in metadata
 * so the null actor reads as deliberate.
 */
class PlatformAudit
{
    public static function record(string $action, ?string $businessId, array $metadata = []): PlatformAuditLog
    {
        return PlatformAuditLog::create([
            'admin_user_id' => auth()->id(),
            'action' => $action,
            'target_business_id' => $businessId,
            'metadata' => $metadata,
        ]);
    }
}

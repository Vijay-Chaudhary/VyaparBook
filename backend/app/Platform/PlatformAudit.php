<?php
// app/Platform/PlatformAudit.php

namespace App\Platform;

use App\Models\PlatformAuditLog;

/**
 * Writes the platform's mutation trail. The actor is read from auth()->id():
 * platform routes run auth:api but NOT tenant.context (they carry no tenant),
 * so app('tenant.user_id') is unavailable there — the authenticated api guard is.
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

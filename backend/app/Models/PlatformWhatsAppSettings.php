<?php
// app/Models/PlatformWhatsAppSettings.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The single platform WhatsApp configuration row.
 *
 * The three secrets use the `encrypted` cast: the database never holds them in
 * plaintext, so a database dump, a replica, or a backup is not a credential
 * leak. They are also never rendered back to the browser — see
 * Admin\WhatsAppSettingsController.
 */
class PlatformWhatsAppSettings extends Model
{
    use HasUuids;

    protected $table = 'platform_whatsapp_settings';

    protected $fillable = [
        'driver', 'api_version', 'phone_number_id', 'token',
        'template', 'verify_token', 'app_secret', 'updated_by',
    ];

    protected $casts = [
        'token' => 'encrypted',
        'verify_token' => 'encrypted',
        'app_secret' => 'encrypted',
    ];

    /** The one row, or null when the platform still runs purely off .env. */
    public static function current(): ?self
    {
        return static::query()->orderBy('created_at')->first();
    }
}

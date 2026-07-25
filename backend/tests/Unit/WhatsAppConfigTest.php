<?php

use App\Models\PlatformWhatsAppSettings;
use App\Reminders\WhatsAppConfig;

beforeEach(function () {
    config()->set('services.whatsapp.driver', 'log');
    config()->set('services.whatsapp.token', 'env-token');
    config()->set('services.whatsapp.phone_number_id', 'env-phone');
});

it('falls back to env when nothing is stored, so env-only deploys are unaffected', function () {
    expect(WhatsAppConfig::get('token'))->toBe('env-token');
    expect(WhatsAppConfig::driver())->toBe('log');
    expect(WhatsAppConfig::source('token'))->toBe('env');
});

it('lets a stored value override env', function () {
    PlatformWhatsAppSettings::create([
        'driver' => 'cloud_api', 'token' => 'db-token', 'phone_number_id' => 'db-phone',
    ]);

    expect(WhatsAppConfig::get('token'))->toBe('db-token');
    expect(WhatsAppConfig::driver())->toBe('cloud_api');
    expect(WhatsAppConfig::source('token'))->toBe('console');
});

it('falls back per field, so a half-filled row does not blank out env', function () {
    PlatformWhatsAppSettings::create([
        'driver' => 'cloud_api', 'token' => 'db-token', 'phone_number_id' => null,
    ]);

    expect(WhatsAppConfig::get('token'))->toBe('db-token');
    expect(WhatsAppConfig::get('phone_number_id'))->toBe('env-phone');   // not null
    expect(WhatsAppConfig::source('phone_number_id'))->toBe('env');
});

it('treats an empty string as absent rather than as a deliberate blank', function () {
    PlatformWhatsAppSettings::create(['driver' => 'cloud_api', 'token' => '']);

    expect(WhatsAppConfig::get('token'))->toBe('env-token');
});

it('stores secrets encrypted, so a database dump is not a credential leak', function () {
    PlatformWhatsAppSettings::create(['driver' => 'cloud_api', 'token' => 'super-secret-token']);

    $raw = Illuminate\Support\Facades\DB::table('platform_whatsapp_settings')->value('token');

    expect($raw)->not->toContain('super-secret-token');
    expect(PlatformWhatsAppSettings::current()->token)->toBe('super-secret-token');
});

<?php
// tests/Feature/Web/ConsoleWhatsAppTest.php

use App\Models\PlatformAuditLog;
use App\Models\PlatformWhatsAppSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function waAdmin(): User
{
    return User::factory()->create(['is_platform_admin' => true]);
}

describe('access', function () {
    it('keeps the settings behind the platform-admin gate', function () {
        $this->actingAs(User::factory()->create(['is_platform_admin' => false]))
            ->get('/admin/console/whatsapp')
            ->assertForbidden();
    });

    it('redirects a guest to login', function () {
        $this->get('/admin/console/whatsapp')->assertRedirect(route('login'));
    });
});

describe('saving', function () {
    it('stores the settings and records who changed them', function () {
        $admin = waAdmin();

        $this->actingAs($admin)->post('/admin/console/whatsapp', [
            'driver' => 'cloud_api',
            'api_version' => 'v21.0',
            'phone_number_id' => '11112222',
            'template' => 'payment_reminder',
            'token' => 'secret-token',
            'verify_token' => 'verify-me',
            'app_secret' => 'app-secret',
        ])->assertRedirect(route('admin.console.whatsapp'));

        $row = PlatformWhatsAppSettings::current();
        expect($row->driver)->toBe('cloud_api');
        expect($row->token)->toBe('secret-token');
        expect($row->updated_by)->toBe($admin->id);

        expect(PlatformAuditLog::where('action', 'whatsapp_settings_saved')->count())->toBe(1);
    });

    it('never writes a secret to the database in plaintext', function () {
        $this->actingAs(waAdmin())->post('/admin/console/whatsapp', [
            'driver' => 'cloud_api', 'token' => 'super-secret-token',
            'app_secret' => 'super-secret-app', 'verify_token' => 'super-secret-verify',
        ]);

        $raw = DB::table('platform_whatsapp_settings')->first();

        expect($raw->token)->not->toContain('super-secret-token');
        expect($raw->app_secret)->not->toContain('super-secret-app');
        expect($raw->verify_token)->not->toContain('super-secret-verify');
    });

    it('never renders a stored secret back to the browser', function () {
        PlatformWhatsAppSettings::create([
            'driver' => 'cloud_api', 'token' => 'super-secret-token',
            'app_secret' => 'super-secret-app', 'verify_token' => 'super-secret-verify',
        ]);

        $response = $this->actingAs(waAdmin())->get('/admin/console/whatsapp')->assertOk();

        // A secret that can be read off a page leaks via screenshots and caches.
        expect($response->getContent())->not->toContain('super-secret-token');
        expect($response->getContent())->not->toContain('super-secret-app');
        expect($response->getContent())->not->toContain('super-secret-verify');
    });

    it('keeps an existing secret when the field is submitted blank', function () {
        PlatformWhatsAppSettings::create(['driver' => 'cloud_api', 'token' => 'original-token']);

        $this->actingAs(waAdmin())->post('/admin/console/whatsapp', [
            'driver' => 'cloud_api', 'phone_number_id' => '9999', 'token' => '',
        ]);

        expect(PlatformWhatsAppSettings::current()->token)->toBe('original-token');
        expect(PlatformWhatsAppSettings::current()->phone_number_id)->toBe('9999');
    });

    it('rejects an unknown driver', function () {
        $this->actingAs(waAdmin())->post('/admin/console/whatsapp', ['driver' => 'carrier-pigeon'])
            ->assertSessionHasErrors('driver');
    });

    it('shows whether each live value comes from the console or .env', function () {
        config()->set('services.whatsapp.phone_number_id', 'from-env');

        $this->actingAs(waAdmin())->get('/admin/console/whatsapp')
            ->assertOk()
            ->assertSee(__('admin.whatsapp_source_env'));
    });
});

describe('test connection', function () {
    it('refuses to test while the driver is log, since it would prove nothing', function () {
        Http::preventStrayRequests();
        PlatformWhatsAppSettings::create(['driver' => 'log']);

        $this->actingAs(waAdmin())->post('/admin/console/whatsapp/test', ['to' => '9876543210'])
            ->assertRedirect(route('admin.console.whatsapp'));

        Http::assertNothingSent();
    });

    it('sends a real message and reports the provider message id', function () {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]], 200)]);
        PlatformWhatsAppSettings::create([
            'driver' => 'cloud_api', 'phone_number_id' => '11112222',
            'token' => 't', 'template' => 'payment_reminder', 'api_version' => 'v21.0',
        ]);

        $this->actingAs(waAdmin())->post('/admin/console/whatsapp/test', ['to' => '9876543210'])
            ->assertRedirect(route('admin.console.whatsapp'))
            ->assertSessionHas('whatsapp_test');

        Http::assertSent(fn ($request) => $request->data()['to'] === '919876543210');
        expect(PlatformAuditLog::where('action', 'whatsapp_test_send')->count())->toBe(1);
    });

    it('shows Meta\'s error verbatim when the send fails', function () {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['code' => 132001, 'message' => 'Template name does not exist'],
        ], 400)]);
        PlatformWhatsAppSettings::create([
            'driver' => 'cloud_api', 'phone_number_id' => '1', 'token' => 't',
            'template' => 'wrong_name', 'api_version' => 'v21.0',
        ]);

        $this->actingAs(waAdmin())->post('/admin/console/whatsapp/test', ['to' => '9876543210'])
            ->assertRedirect(route('admin.console.whatsapp'));

        // A truncated integration error is useless: the code and text both survive.
        $result = session('whatsapp_test');
        expect($result['ok'])->toBeFalse();
        expect($result['message'])->toContain('Template name does not exist');
        expect($result['code'])->toBe('132001');
    });

    it('refuses a phone number it cannot normalise', function () {
        Http::preventStrayRequests();
        PlatformWhatsAppSettings::create(['driver' => 'cloud_api', 'phone_number_id' => '1', 'token' => 't']);

        $this->actingAs(waAdmin())->post('/admin/console/whatsapp/test', ['to' => '123'])
            ->assertSessionHasErrors('to');

        Http::assertNothingSent();
    });
});

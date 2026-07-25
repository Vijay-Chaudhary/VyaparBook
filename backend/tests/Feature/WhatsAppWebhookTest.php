<?php
// tests/Feature/WhatsAppWebhookTest.php

use App\Models\Customer;
use App\Models\ReminderLog;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('services.whatsapp.verify_token', 'verify-me');
    config()->set('services.whatsapp.app_secret', 'app-secret');
});

/** Sign a payload the way Meta does, so the happy path is exercised honestly. */
function waSigned(array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return [$body, 'sha256='.hash_hmac('sha256', $body, 'app-secret')];
}

function waPost(array $payload, ?string $signature = null)
{
    [$body, $valid] = waSigned($payload);

    return test()->call(
        'POST', '/webhooks/whatsapp', [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature ?? $valid],
        $body,
    );
}

/** A cloud_api reminder already handed to Meta. */
function sentReminder(string $providerMessageId = 'wamid.ABC', string $phone = '9876543210'): array
{
    [$owner, $business] = pwOwner();

    $customer = Customer::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Ramesh Kumar', 'village' => 'Rampur',
        'phone' => $phone, 'opening_balance' => '0.00',
    ]);

    $log = new ReminderLog([
        'business_id' => $business->id, 'customer_id' => $customer->id,
        'channel' => 'cloud_api', 'amount_at_send' => '2500.00',
        'locale' => 'en', 'phone_e164' => '91'.$phone,
    ]);
    $log->setConnection('pgsql_migrate');
    $log->created_by = $owner->id;
    $log->status = 'sent';
    $log->provider_message_id = $providerMessageId;
    $log->save();

    return [$business, $customer, $log];
}

function statusPayload(string $id, string $status): array
{
    return ['entry' => [['changes' => [['value' => [
        'statuses' => [['id' => $id, 'status' => $status, 'timestamp' => (string) time()]],
    ]]]]]];
}

function inboundPayload(string $from, string $text): array
{
    return ['entry' => [['changes' => [['value' => [
        'messages' => [['from' => $from, 'type' => 'text', 'text' => ['body' => $text]]],
    ]]]]]];
}

describe('subscription handshake', function () {
    it('echoes the challenge when the verify token matches', function () {
        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=12345')
            ->assertOk()
            ->assertSee('12345');
    });

    it('refuses a wrong verify token', function () {
        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345')
            ->assertForbidden();
    });
});

describe('signature verification', function () {
    it('rejects a payload with no signature and writes nothing', function () {
        [, , $log] = sentReminder();

        test()->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(statusPayload('wamid.ABC', 'delivered')))
            ->assertForbidden();

        expect(ReminderLog::on('pgsql_migrate')->find($log->id)->status)->toBe('sent');
    });

    it('rejects a forged signature and writes nothing', function () {
        [, , $log] = sentReminder();

        waPost(statusPayload('wamid.ABC', 'delivered'), signature: 'sha256=deadbeef')
            ->assertForbidden();

        expect(ReminderLog::on('pgsql_migrate')->find($log->id)->status)->toBe('sent');
    });
});

describe('delivery status', function () {
    it('advances the row to delivered', function () {
        [, , $log] = sentReminder();

        waPost(statusPayload('wamid.ABC', 'delivered'))->assertOk();

        $fresh = ReminderLog::on('pgsql_migrate')->find($log->id);
        expect($fresh->status)->toBe('delivered');
        expect($fresh->status_at)->not->toBeNull();
    });

    it('never moves a status backwards when callbacks arrive out of order', function () {
        [, , $log] = sentReminder();

        waPost(statusPayload('wamid.ABC', 'read'))->assertOk();
        waPost(statusPayload('wamid.ABC', 'sent'))->assertOk();   // late, out of order

        expect(ReminderLog::on('pgsql_migrate')->find($log->id)->status)->toBe('read');
    });

    it('records a failure reported by Meta', function () {
        [, , $log] = sentReminder();

        $payload = ['entry' => [['changes' => [['value' => ['statuses' => [[
            'id' => 'wamid.ABC', 'status' => 'failed', 'timestamp' => (string) time(),
            'errors' => [['code' => 131026, 'title' => 'Message undeliverable']],
        ]]]]]]]];

        waPost($payload)->assertOk();

        $fresh = ReminderLog::on('pgsql_migrate')->find($log->id);
        expect($fresh->status)->toBe('failed');
        expect($fresh->error_code)->toBe('131026');
    });

    it('ignores a status for a message id it has never seen', function () {
        waPost(statusPayload('wamid.UNKNOWN', 'delivered'))->assertOk();

        expect(ReminderLog::on('pgsql_migrate')->count())->toBe(0);
    });
});

describe('inbound STOP', function () {
    it('opts the person out in EVERY tenant that holds their number', function () {
        // The platform number receives replies for all tenants, and the person
        // saying STOP cannot say which shop they mean — they mean us.
        [, $mine] = sentReminder('wamid.A', '9876543210');
        [, $theirs] = sentReminder('wamid.B', '9876543210');   // different tenant, same phone

        waPost(inboundPayload('919876543210', 'STOP'))->assertOk();

        expect(Customer::on('pgsql_migrate')->find($mine->id)->reminder_opt_out_at)->not->toBeNull();
        expect(Customer::on('pgsql_migrate')->find($theirs->id)->reminder_opt_out_at)->not->toBeNull();
    });

    it('recognises Hindi stop words and ignores case and punctuation', function () {
        [, $customer] = sentReminder('wamid.A', '9876543210');

        waPost(inboundPayload('919876543210', ' बंद '))->assertOk();

        expect(Customer::on('pgsql_migrate')->find($customer->id)->reminder_opt_out_at)->not->toBeNull();
    });

    it('does not opt out on a sentence that merely contains a stop word', function () {
        [, $customer] = sentReminder('wamid.A', '9876543210');

        waPost(inboundPayload('919876543210', "please don't stop sending, I will pay tomorrow"))
            ->assertOk();

        expect(Customer::on('pgsql_migrate')->find($customer->id)->reminder_opt_out_at)->toBeNull();
    });

    it('ignores a stop from a number nobody has on file', function () {
        [, $customer] = sentReminder('wamid.A', '9876543210');

        waPost(inboundPayload('919999999999', 'STOP'))->assertOk();

        expect(Customer::on('pgsql_migrate')->find($customer->id)->reminder_opt_out_at)->toBeNull();
    });

    it('leaves an ordinary reply alone', function () {
        [, $customer] = sentReminder('wamid.A', '9876543210');

        waPost(inboundPayload('919876543210', 'I will pay on Friday'))->assertOk();

        expect(Customer::on('pgsql_migrate')->find($customer->id)->reminder_opt_out_at)->toBeNull();
    });
});

<?php

use App\Reminders\CloudApiSender;
use App\Reminders\LogSender;
use Illuminate\Support\Facades\Http;

describe('LogSender', function () {
    it('accepts the message and returns a synthetic id without any network call', function () {
        Http::preventStrayRequests();   // any real request would now throw

        $result = (new LogSender)->send('919876543210', 'Please pay ₹500.00', 'en');

        expect($result->accepted)->toBeTrue();
        expect($result->providerMessageId)->not->toBeNull();
        expect($result->providerMessageId)->toStartWith('log-');
        Http::assertNothingSent();
    });
});

describe('CloudApiSender', function () {
    beforeEach(function () {
        config()->set('services.whatsapp.api_version', 'v21.0');
        config()->set('services.whatsapp.phone_number_id', '11112222');
        config()->set('services.whatsapp.token', 'test-token');
        config()->set('services.whatsapp.template', 'payment_reminder');
    });

    it('posts a template message to the configured number and parses the message id', function () {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'messages' => [['id' => 'wamid.HBgM123']],
        ], 200)]);

        $result = (new CloudApiSender)->send(
            '919876543210', 'Please pay ₹500.00', 'en', ['Sharma Namkeen', '₹500.00'],
        );

        expect($result->accepted)->toBeTrue();
        expect($result->providerMessageId)->toBe('wamid.HBgM123');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://graph.facebook.com/v21.0/11112222/messages'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $body['to'] === '919876543210'
                && $body['type'] === 'template'
                && $body['template']['name'] === 'payment_reminder'
                && $body['template']['language']['code'] === 'en'
                && $body['template']['components'][0]['parameters'][0]['text'] === 'Sharma Namkeen'
                && $body['template']['components'][0]['parameters'][1]['text'] === '₹500.00';
        });
    });

    it('selects the Hindi template language for a Hindi shop', function () {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

        (new CloudApiSender)->send('919876543210', 'नमस्ते', 'hi', ['Sharma', '₹500.00']);

        Http::assertSent(fn ($request) => $request->data()['template']['language']['code'] === 'hi');
    });

    it('treats a 4xx as a permanent failure that must not be retried', function () {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['code' => 131026, 'message' => 'Message undeliverable'],
        ], 400)]);

        $result = (new CloudApiSender)->send('919876543210', 'x', 'en', ['a', 'b']);

        expect($result->accepted)->toBeFalse();
        expect($result->errorCode)->toBe('131026');
        expect($result->errorMessage)->toContain('undeliverable');
        expect($result->retryable)->toBeFalse();
    });

    it('treats a 5xx as retryable so a transient outage is not a lost reminder', function () {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'oops']], 503)]);

        $result = (new CloudApiSender)->send('919876543210', 'x', 'en', ['a', 'b']);

        expect($result->accepted)->toBeFalse();
        expect($result->retryable)->toBeTrue();
    });

    it('treats a connection failure as retryable rather than throwing', function () {
        Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('timeout'));

        $result = (new CloudApiSender)->send('919876543210', 'x', 'en', ['a', 'b']);

        expect($result->accepted)->toBeFalse();
        expect($result->retryable)->toBeTrue();
    });
});

describe('driver resolution', function () {
    it('resolves the log driver by default, so the integration ships dark', function () {
        expect(app(App\Reminders\Contracts\WhatsAppSender::class))->toBeInstanceOf(LogSender::class);
    });

    it('resolves the cloud api driver when configured', function () {
        config()->set('services.whatsapp.driver', 'cloud_api');

        expect(app(App\Reminders\Contracts\WhatsAppSender::class))->toBeInstanceOf(CloudApiSender::class);
    });

    it('throws on an unknown driver rather than silently sending nothing', function () {
        config()->set('services.whatsapp.driver', 'typo');

        expect(fn () => app(App\Reminders\Contracts\WhatsAppSender::class))
            ->toThrow(InvalidArgumentException::class);
    });
});

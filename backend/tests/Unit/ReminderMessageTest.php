<?php

use App\Reminders\ReminderMessage;

describe('phone normalisation', function () {
    it('prefixes a bare 10-digit Indian mobile', function () {
        expect(ReminderMessage::normalisePhone('9876543210'))->toBe('919876543210');
    });

    it('accepts numbers already carrying a country code, without doubling it', function () {
        expect(ReminderMessage::normalisePhone('+91 98765 43210'))->toBe('919876543210');
        expect(ReminderMessage::normalisePhone('0091-9876543210'))->toBe('919876543210');
        expect(ReminderMessage::normalisePhone('919876543210'))->toBe('919876543210');
    });

    it('strips punctuation and whitespace', function () {
        expect(ReminderMessage::normalisePhone(' (98765) 43210 '))->toBe('919876543210');
        expect(ReminderMessage::normalisePhone('98765-43210'))->toBe('919876543210');
    });

    it('drops a leading zero on a local-format number', function () {
        expect(ReminderMessage::normalisePhone('09876543210'))->toBe('919876543210');
    });

    it('refuses anything it cannot trust rather than emitting a broken link', function () {
        expect(ReminderMessage::normalisePhone(null))->toBeNull();
        expect(ReminderMessage::normalisePhone(''))->toBeNull();
        expect(ReminderMessage::normalisePhone('98765'))->toBeNull();          // too short
        expect(ReminderMessage::normalisePhone('9876543210987654'))->toBeNull(); // too long
        expect(ReminderMessage::normalisePhone('not a phone'))->toBeNull();
        expect(ReminderMessage::normalisePhone('00000'))->toBeNull();
    });
});

describe('message composition', function () {
    it('names the shop and the amount owed, in English', function () {
        $text = ReminderMessage::text('Sharma Namkeen', '1250.50', 'en');

        expect($text)->toContain('Sharma Namkeen');
        expect($text)->toContain('₹1,250.50');
    });

    it('renders in Hindi for a Hindi-language shop', function () {
        $hi = ReminderMessage::text('Sharma Namkeen', '1250.50', 'hi');
        $en = ReminderMessage::text('Sharma Namkeen', '1250.50', 'en');

        expect($hi)->not->toBe($en);
        expect($hi)->toContain('Sharma Namkeen');   // the shop name is not translated
        expect($hi)->toContain('₹1,250.50');
        expect($hi)->toMatch('/\p{Devanagari}/u'); // actually Hindi, not a fallback to en
    });

    it('builds a wa.me url with the normalised number and percent-encoded text', function () {
        $url = ReminderMessage::url('9876543210', 'Sharma Namkeen', '1250.50', 'en');

        expect($url)->toStartWith('https://wa.me/919876543210?text=');
        expect($url)->not->toContain(' ');                  // encoded, never raw spaces
        expect(urldecode($url))->toContain('Sharma Namkeen');
        expect(urldecode($url))->toContain('₹1,250.50');
    });

    it('returns null for a url it cannot address', function () {
        expect(ReminderMessage::url('98765', 'Sharma Namkeen', '1250.50', 'en'))->toBeNull();
        expect(ReminderMessage::url(null, 'Sharma Namkeen', '1250.50', 'en'))->toBeNull();
    });
});

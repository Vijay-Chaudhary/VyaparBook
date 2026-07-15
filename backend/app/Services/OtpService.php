<?php

namespace App\Services;

use App\Models\OtpCode;
use Carbon\Carbon;

class OtpService
{
    private const CODE_TTL_MINUTES = 5;
    private const MAX_ATTEMPTS = 5;

    public function generate(string $phone): string
    {
        $code = (string) random_int(100000, 999999);

        OtpCode::create([
            'phone' => $phone,
            'code_hash' => hash('sha256', $code),
            'expires_at' => Carbon::now()->addMinutes(self::CODE_TTL_MINUTES),
        ]);

        return $code;
    }

    public function verify(string $phone, string $code): bool
    {
        $otp = OtpCode::where('phone', $phone)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', Carbon::now())
            ->latest('id')
            ->first();

        if (! $otp || $otp->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        $otp->increment('attempts');

        if (! hash_equals($otp->code_hash, hash('sha256', $code))) {
            return false;
        }

        $otp->update(['consumed_at' => Carbon::now()]);

        return true;
    }
}

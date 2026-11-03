<?php

namespace App\Services;

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

/**
 * Two-factor authentication for staff: time based codes (TOTP, as an
 * authenticator app makes them) and single-use recovery codes.
 *
 * A code is good once: the time step of the last accepted code is kept, and
 * anything at or before it is refused, so a code seen over a shoulder cannot be
 * replayed within its thirty seconds.
 */
class TwoFactor
{
    public const RECOVERY_CODES = 8;

    public function __construct(private readonly Google2FA $google2fa)
    {
    }

    /** Starts set-up: a new secret, not yet in force until confirmed. */
    public function begin(User $user): string
    {
        $secret = $this->google2fa->generateSecretKey(32);
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_step' => null,
        ])->save();

        return $secret;
    }

    /** The otpauth:// address an authenticator app reads from the QR code. */
    public function uri(User $user): string
    {
        return $this->google2fa->getQRCodeUrl(config('app.name'), $user->email, $user->two_factor_secret);
    }

    /** Checks a code against the user's secret and, if good, remembers its step. */
    public function verify(User $user, string $code): bool
    {
        if (! $user->two_factor_secret) {
            return false;
        }

        // With no earlier step the library answers `true` rather than the step
        // that matched, and that `true` would be stored as step 1 and let the
        // same code in again. Starting from 0 makes it always say which step.
        $step = $this->google2fa->verifyKeyNewer(
            $user->two_factor_secret,
            preg_replace('/\s+/', '', $code),
            $user->two_factor_last_step ?? 0,
            1,
        );

        if ($step === false) {
            return false;
        }

        $user->forceFill(['two_factor_last_step' => $step])->save();

        return true;
    }

    /** Confirms set-up with the first good code and returns the recovery codes, shown once. @return list<string> */
    public function confirm(User $user, string $code): ?array
    {
        if (! $this->verify($user, $code)) {
            return null;
        }

        $codes = collect(range(1, self::RECOVERY_CODES))
            ->map(fn () => strtolower(\Illuminate\Support\Str::random(5).'-'.\Illuminate\Support\Str::random(5)))
            ->all();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            // Only the hashes are kept: the codes cannot be read back.
            'two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes),
        ])->save();

        return $codes;
    }

    /** Spends a recovery code: true once, false ever after. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $hash = hash('sha256', strtolower(trim($code)));
        $codes = (array) $user->two_factor_recovery_codes;

        if (! in_array($hash, $codes, true)) {
            return false;
        }

        $user->forceFill(['two_factor_recovery_codes' => array_values(array_diff($codes, [$hash]))])->save();

        return true;
    }
}

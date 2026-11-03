<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Checks a password in the same time whether or not the account exists.
 *
 * Hashing a password is deliberately slow. If an unknown email skipped it, the
 * answer would come back much faster than for a real account with a wrong
 * password, and anyone could tell by the clock which emails belong to staff.
 * So when there is no account, or it is switched off, the password is still
 * hashed, against a hash that can never match.
 */
class PasswordVerifier
{
    public function __construct(private readonly Hasher $hasher)
    {
    }

    /** True only for a real, active account and the right password. */
    public function verify(?User $user, string $password): bool
    {
        $usable = $user !== null && $user->is_active;

        // A real hash of random text, made once and kept, at the cost the app
        // really uses (the cost is part of the key, so changing it renews it).
        $dummy = Cache::rememberForever(
            'login.dummy-hash.'.config('hashing.bcrypt.rounds'),
            fn () => $this->hasher->make(Str::random(40)),
        );

        $matches = $this->hasher->check($password, $usable ? $user->password : $dummy);

        return $usable && $matches;
    }
}

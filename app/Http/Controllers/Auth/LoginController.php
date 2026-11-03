<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PasswordVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public const MAX_ATTEMPTS = 5;

    /** Failed sign-ins from one address, across every email, in a minute. */
    public const MAX_ATTEMPTS_PER_ADDRESS = 20;

    /** How long a password that was right is good for at the code step. */
    public const CHALLENGE_MINUTES = 10;

    public function show()
    {
        return inertia('Auth/Login');
    }

    /**
     * Checks the password. A member of staff with two-factor on is not signed
     * in yet: they are held at the challenge until the code is right. One
     * without it is signed in and sent to set it up, which they must do.
     */
    public function store(Request $request, PasswordVerifier $passwords)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        // Two limits: this email from this address, and this address whatever
        // the email, so one machine cannot walk through a list of them.
        $key = Str::lower($data['email']).'|'.$request->ip();
        $addressKey = 'login-address|'.$request->ip();
        foreach ([[$key, self::MAX_ATTEMPTS], [$addressKey, self::MAX_ATTEMPTS_PER_ADDRESS]] as [$limited, $max]) {
            if (RateLimiter::tooManyAttempts($limited, $max)) {
                throw ValidationException::withMessages([
                    'email' => __('control.auth.throttle', ['seconds' => RateLimiter::availableIn($limited)]),
                ]);
            }
        }

        $user = User::query()->where('email', $data['email'])->first();

        // The same answer, in the same time, for a wrong password, an unknown
        // email and a switched-off account, so none can be told from the others.
        if (! $passwords->verify($user, $data['password'])) {
            RateLimiter::hit($key, 60);
            RateLimiter::hit($addressKey, 60);

            throw ValidationException::withMessages(['email' => __('control.auth.failed')]);
        }

        RateLimiter::clear($key);

        if ($user->hasTwoFactor()) {
            $request->session()->regenerate();
            $request->session()->put('two_factor.user_id', $user->id);
            $request->session()->put('two_factor.at', now()->timestamp);

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('two-factor.setup');
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

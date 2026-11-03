<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public const MAX_ATTEMPTS = 5;

    public function show()
    {
        return inertia('Auth/Login');
    }

    /**
     * Checks the password. A member of staff with two-factor on is not signed
     * in yet: they are held at the challenge until the code is right. One
     * without it is signed in and sent to set it up, which they must do.
     */
    public function store(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        $key = Str::lower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => __('control.auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $user = User::query()->where('email', $data['email'])->first();

        // The same answer for a wrong password, an unknown email and a switched
        // off account, so none of the three can be told from the others.
        if (! $user || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['email' => __('control.auth.failed')]);
        }

        RateLimiter::clear($key);

        if ($user->hasTwoFactor()) {
            $request->session()->regenerate();
            $request->session()->put('two_factor.user_id', $user->id);

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

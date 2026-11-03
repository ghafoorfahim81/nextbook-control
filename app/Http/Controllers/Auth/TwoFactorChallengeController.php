<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** The second step of signing in: the code from the authenticator app, or a recovery code. */
class TwoFactorChallengeController extends Controller
{
    /** Whether a password that was right a moment ago is still good for a code. */
    private function pending(Request $request): bool
    {
        $session = $request->session();
        $fresh = $session->has('two_factor.user_id')
            && (now()->timestamp - (int) $session->get('two_factor.at', 0)) <= LoginController::CHALLENGE_MINUTES * 60;

        if (! $fresh) {
            $session->forget(['two_factor.user_id', 'two_factor.at']);
        }

        return $fresh;
    }

    public function show(Request $request)
    {
        if (! $this->pending($request)) {
            return redirect()->route('login');
        }

        return inertia('Auth/TwoFactorChallenge');
    }

    public function store(Request $request, TwoFactor $twoFactor)
    {
        if (! $this->pending($request)) {
            return redirect()->route('login');
        }

        $user = User::query()->find($request->session()->get('two_factor.user_id'));
        if (! $user || ! $user->is_active) {
            $request->session()->forget(['two_factor.user_id', 'two_factor.at']);

            return redirect()->route('login');
        }

        $data = $request->validate(['code' => ['nullable', 'string'], 'recovery_code' => ['nullable', 'string']]);
        if (empty($data['code']) && empty($data['recovery_code'])) {
            throw ValidationException::withMessages(['code' => __('control.auth.invalid_code')]);
        }

        // Six digits are guessable, so the guessing is limited per account.
        $key = 'two-factor|'.$user->id;
        if (RateLimiter::tooManyAttempts($key, LoginController::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'code' => __('control.auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $good = ! empty($data['recovery_code'])
            ? $twoFactor->useRecoveryCode($user, $data['recovery_code'])
            : $twoFactor->verify($user, $data['code']);

        if (! $good) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                empty($data['recovery_code']) ? 'code' : 'recovery_code' => __('control.auth.invalid_code'),
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->forget(['two_factor.user_id', 'two_factor.at']);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}

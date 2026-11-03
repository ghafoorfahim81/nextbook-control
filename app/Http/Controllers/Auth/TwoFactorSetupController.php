<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Setting two-factor up, which every staff account must do. Until it is done,
 * RequireTwoFactor keeps the account on this page.
 */
class TwoFactorSetupController extends Controller
{
    public function show(Request $request, TwoFactor $twoFactor)
    {
        $user = $request->user();

        // The codes are shown once, right after confirming, from the session.
        $recovery = $request->session()->get('recovery_codes');
        if ($recovery) {
            return inertia('Auth/TwoFactorSetup', ['step' => 'recovery', 'recoveryCodes' => $recovery]);
        }

        if ($user->hasTwoFactor()) {
            return redirect()->route('dashboard');
        }

        // A secret is made the first time, then kept while the page is
        // reloaded, so the QR code on screen stays the one in the app.
        if (! $user->two_factor_secret) {
            $twoFactor->begin($user);
            $user->refresh();
        }

        return inertia('Auth/TwoFactorSetup', [
            'step' => 'scan',
            'secret' => $user->two_factor_secret,
            'otpauthUri' => $twoFactor->uri($user),
        ]);
    }

    public function store(Request $request, TwoFactor $twoFactor)
    {
        $data = $request->validate(['code' => ['required', 'string']]);

        $codes = $twoFactor->confirm($request->user(), $data['code']);
        if ($codes === null) {
            throw ValidationException::withMessages(['code' => __('control.auth.invalid_code')]);
        }

        return redirect()->route('two-factor.setup')->with('recovery_codes', $codes);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** A member of staff's own account: for now, their own password. Open to every role. */
class AccountController extends Controller
{
    public function show(Request $request)
    {
        return inertia('Account/Password', ['forced' => (bool) $request->user()->must_change_password]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            // Long beats clever: twelve characters at least, and not the one it replaces.
            'password' => ['required', 'string', 'min:12', 'confirmed', 'different:current_password'],
        ]);

        $user->forceFill([
            'password' => $data['password'],
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        // Whoever else was signed in as this account (the old password may have
        // been the reason for changing it) is signed out; this session stays.
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        $request->session()->regenerate();

        Audit::record('staff.password_changed', $user);

        return redirect()->route('dashboard')->with('success', __('control.saved'));
    }
}

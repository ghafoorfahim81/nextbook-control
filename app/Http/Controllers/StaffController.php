<?php

namespace App\Http\Controllers;

use App\Exceptions\ControlException;
use App\Models\User;
use App\Services\Audit;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Who works in the control center, and what they may do. The owner only
 * (staff.manage).
 *
 * Whoever holds this screen can give anyone any power, so it has rules of its
 * own: you cannot switch yourself off, and the last active owner can be
 * neither switched off nor given another role, or nobody would be left who
 * could run the staff. Anything that changes what a person can do, or how they
 * sign in, ends their sessions at once. Passwords are generated here and
 * shown once; the audit trail never holds one.
 */
class StaffController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('staff.manage');

        return inertia('Staff/Index', [
            'staff' => User::query()->orderBy('name')->get()->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => $user->is_active,
                'two_factor' => $user->hasTwoFactor(),
                'is_me' => $user->is(Auth::user()),
            ]),
            // Shown once, right after it was made.
            'generatedPassword' => $request->session()->get('generated_password'),
            'generatedFor' => $request->session()->get('generated_for'),
        ]);
    }

    public function create()
    {
        Gate::authorize('staff.manage');

        return inertia('Staff/Form', ['member' => null, 'roles' => Roles::names()]);
    }

    public function store(Request $request)
    {
        Gate::authorize('staff.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(Roles::names())],
        ]);

        $password = Str::password(16, symbols: false);

        $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $password]);
        $user->forceFill(['role' => $data['role'], 'is_active' => true])->save();
        Audit::record('staff.created', $user, null, $user->only(['name', 'email', 'role']));

        return redirect()->route('staff.index')->with(['generated_password' => $password, 'generated_for' => $user->email]);
    }

    public function edit(User $user)
    {
        Gate::authorize('staff.manage');

        return inertia('Staff/Form', [
            'member' => $user->only(['id', 'name', 'email', 'role', 'is_active']) + ['is_me' => $user->is(Auth::user())],
            'roles' => Roles::names(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        Gate::authorize('staff.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(Roles::names())],
            'is_active' => ['boolean'],
        ]);
        $active = (bool) ($data['is_active'] ?? false);

        if ($user->is(Auth::user()) && ! $active) {
            throw new ControlException(__('control.staff.self_off'));
        }
        if (($data['role'] !== 'owner' || ! $active) && $this->isLastOwner($user)) {
            throw new ControlException(__('control.staff.last_owner'));
        }

        $before = $user->only(['name', 'role', 'is_active']);
        $user->forceFill(['name' => $data['name'], 'role' => $data['role'], 'is_active' => $active])->save();
        Audit::record('staff.updated', $user, $before, $user->only(['name', 'role', 'is_active']));

        // A different role or a switched-off account takes effect now, not at the next sign-in.
        if ($before['role'] !== $data['role'] || ! $active) {
            $this->endSessions($user);
        }

        return redirect()->route('staff.index')->with('success', __('control.saved'));
    }

    /** Makes a new password, shown once. The old one stops working and so do their sessions. */
    public function resetPassword(User $user)
    {
        Gate::authorize('staff.manage');

        $password = Str::password(16, symbols: false);
        $user->forceFill(['password' => $password])->save();
        $this->endSessions($user);
        Audit::record('staff.password_reset', $user);

        return back()->with(['generated_password' => $password, 'generated_for' => $user->email]);
    }

    /** For a lost phone with no recovery codes: they set two-factor up again at their next sign-in. */
    public function resetTwoFactor(User $user)
    {
        Gate::authorize('staff.manage');

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
        $this->endSessions($user);
        Audit::record('staff.two_factor_reset', $user);

        return back()->with('success', __('control.saved'));
    }

    /** Whether this person is the only active owner left. */
    private function isLastOwner(User $user): bool
    {
        return $user->role === 'owner' && $user->is_active
            && ! User::query()->where('role', 'owner')->where('is_active', true)->whereKeyNot($user->id)->exists();
    }

    private function endSessions(User $user): void
    {
        // With the database session driver this signs them out everywhere; with
        // any other driver the LogOutInactiveStaff and ability checks still apply.
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}

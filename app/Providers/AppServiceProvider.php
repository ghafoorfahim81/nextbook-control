<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // One gate per ability, answered from the role table. A staff member
        // who has been switched off can do nothing, whatever their role.
        foreach (Roles::ABILITIES as $ability) {
            Gate::define($ability, fn (User $user) => $user->is_active && Roles::allows($user->role, $ability));
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates a member of staff, or changes the role or password of one. There is
 * no sign-up page: staff are made here by whoever runs the server, and the
 * first owner has to be.
 *
 *   php artisan control:staff owner@example.com owner --name="Ghafoor"
 *
 * Without --password a strong one is made and shown once.
 */
class MakeStaff extends Command
{
    protected $signature = 'control:staff {email} {role : owner|sales|finance|support|viewer} {--name=} {--password=} {--off : Switch the account off}';

    protected $description = 'Create or update a member of staff';

    public function handle(): int
    {
        $role = $this->argument('role');
        if (! in_array($role, Roles::names(), true)) {
            $this->error('Role must be one of: '.implode(', ', Roles::names()));

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $this->argument('email')]);
        $isNew = ! $user->exists;

        $password = $this->option('password');
        $generated = null;
        if ($isNew && ! $password) {
            $password = $generated = Str::password(16, symbols: false);
        }
        if ($password && strlen($password) < 12) {
            $this->error('The password must be at least 12 characters.');

            return self::FAILURE;
        }

        $user->name = $this->option('name') ?: ($user->name ?: Str::before($user->email, '@'));
        if ($password) {
            $user->password = $password;
        }
        $user->forceFill(['role' => $role, 'is_active' => ! $this->option('off')])->save();

        $this->info(($isNew ? 'Created ' : 'Updated ')."{$user->email} as {$role}.");
        if ($generated) {
            $this->line("Password (shown once): {$generated}");
        }
        $this->line('Two-factor must be set up at first sign-in.');

        return self::SUCCESS;
    }
}

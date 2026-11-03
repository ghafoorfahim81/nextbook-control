<?php

namespace Tests\Support;

use App\Models\Customer;
use App\Models\Package;
use App\Models\User;

trait MakesData
{
    protected function staff(string $role = 'owner', bool $active = true): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => $active])->save();

        return $user;
    }

    protected function customer(array $overrides = []): Customer
    {
        return Customer::query()->create($overrides + ['name_en' => 'Kabul Traders']);
    }

    /** A package with a price of 1200 AFN for 12 months, in force since the start of last year. */
    protected function package(array $overrides = []): Package
    {
        $package = Package::query()->create($overrides + [
            'code' => 'starter',
            'name' => 'Starter',
            'modules' => ['expenses'],
            'max_users' => 3,
            'max_branches' => 1,
        ]);
        $package->prices()->create(['amount' => 1200, 'currency' => 'AFN', 'period_months' => 12, 'effective_from' => now()->subYear()->toDateString()]);

        return $package;
    }

    /** Makes a fresh signing key pair for the test and returns the public key. */
    protected function useLicenseKeys(): string
    {
        $pair = sodium_crypto_sign_keypair();
        config([
            'control.license.private_key' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'control.license.public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ]);

        return base64_encode(sodium_crypto_sign_publickey($pair));
    }
}

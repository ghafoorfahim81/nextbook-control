<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Nothing is seeded on purpose. Staff accounts are made only with
     * `php artisan control:staff`: a seeded account would carry a password
     * that is written in the repository.
     */
    public function run(): void
    {
    }
}

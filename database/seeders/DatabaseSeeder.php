<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        if (config('atlaas.seed_demo_accounts')) {
            $this->call(TestDataSeeder::class);
        }

        $this->call(BuiltInToolsSeeder::class);
    }
}

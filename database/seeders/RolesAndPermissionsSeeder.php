<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['district_admin', 'school_admin', 'teacher', 'student', 'parent'] as $name) {
            Role::findOrCreate($name);
        }
    }
}

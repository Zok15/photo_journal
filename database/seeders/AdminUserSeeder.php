<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = Role::query()->firstOrCreate(['name' => 'super_admin']);
        Role::query()->firstOrCreate(['name' => 'moderator']);

        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin',
                'password' => 'admin12345',
            ]
        );

        $admin->assignRole($superAdmin);
    }
}

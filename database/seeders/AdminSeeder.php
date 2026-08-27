<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A dev login for the Filament panel. Local/testing only — never seed a
 * known staff credential into anything resembling production. Create real
 * accounts from the panel (Platform -> Staff accounts).
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        Admin::firstOrCreate(
            ['email' => 'admin@ase.local'],
            ['name' => 'Aṣẹ Admin', 'password' => Hash::make('password')],
        );
    }
}

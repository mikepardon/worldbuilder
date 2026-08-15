<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CompendiumSourceSeeder::class,
            GlobalAttributeSeeder::class,
        ]);

        User::create([
            'name' => 'Admin',
            'email' => 'admin@worldbuilder.test',
            'password' => Hash::make('password'),
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        User::create([
            'name' => 'Game Master',
            'email' => 'gm@worldbuilder.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        // The read-only demo world every new GM can explore and clone into their own campaign.
        $this->call(SandboxWorldSeeder::class);
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds only the reference and demo data a live environment needs — compendium sources, global
 * attributes and the read-only sandbox world — and deliberately omits the local admin/GM test accounts
 * that {@see DatabaseSeeder} creates (those use a known password and must never exist in production).
 *
 * Every seeder it calls is idempotent (updateOrCreate, or a guard that skips an existing sandbox), so
 * this is safe to run more than once.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CompendiumSourceSeeder::class,
            GlobalAttributeSeeder::class,
            SandboxWorldSeeder::class,
        ]);
    }
}

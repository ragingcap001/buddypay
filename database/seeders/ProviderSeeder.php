<?php

namespace Database\Seeders;

use App\Models\Provider;
use Illuminate\Database\Seeder;

/**
 * Register the external providers known to the platform.
 *
 * A `providers` row gates every provider call (status + circuit breaker +
 * attempt audit trail); the implementation class is resolved from
 * config/ase.php by the ProviderFactory.
 */
class ProviderSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            [
                'name' => 'wema',
                'type' => 'PAYMENT',
                'display_name' => 'Wema Bank (ALAT)',
                'base_url' => config('ase.wema.base_url'),
                'status' => 'ACTIVE',
                'priority' => 20,
            ],
        ];

        foreach ($providers as $attributes) {
            Provider::firstOrCreate(['name' => $attributes['name']], $attributes);
        }
    }
}

<?php

use App\Models\Provider;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The `providers` table gates every provider call (status + circuit
     * breaker + attempt audit trail). Register the built-in rails here so a
     * fresh deploy works without running seeders (seeders remain for the
     * bill catalog and idempotency of the mock provider row).
     */
    public function up(): void
    {
        $defaults = [
            [
                'name' => 'mock',
                'type' => 'BOTH',
                'display_name' => 'Mock Provider',
                'status' => 'ACTIVE',
                'priority' => 10,
            ],
            [
                'name' => 'wema',
                'type' => 'PAYMENT',
                'display_name' => 'Wema Bank (ALAT)',
                'base_url' => config('ase.wema.base_url'),
                'status' => 'ACTIVE',
                'priority' => 20,
            ],
            [
                'name' => 'monnify',
                'type' => 'PAYMENT',
                'display_name' => 'Monnify',
                'base_url' => config('ase.monnify.base_url'),
                'status' => 'ACTIVE',
                'priority' => 20,
            ],
        ];

        foreach ($defaults as $attributes) {
            Provider::firstOrCreate(['name' => $attributes['name']], $attributes);
        }
    }

    public function down(): void
    {
        // Only the rails this migration introduced — `mock` is owned by the
        // bill catalog seeder (bill_providers FK).
        Provider::whereIn('name', ['wema', 'monnify'])->delete();
    }
};

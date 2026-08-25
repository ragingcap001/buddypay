<?php

namespace Database\Seeders;

use App\Models\BillCategory;
use App\Models\BillProduct;
use App\Models\BillProvider;
use App\Models\Provider;
use Illuminate\Database\Seeder;

class BillCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $provider = Provider::firstOrCreate(
            ['name' => 'mock'],
            [
                'type' => 'BOTH',
                'display_name' => 'Mock Provider',
                'status' => 'ACTIVE',
                'priority' => 10,
            ],
        );

        $billProvider = BillProvider::firstOrCreate(
            ['provider_id' => $provider->id, 'display_name' => 'Mock Bills'],
            ['description' => 'Deterministic mock bill provider (dev/test)', 'status' => 'ACTIVE'],
        );

        $categories = [
            ['name' => 'AIRTIME', 'display_name' => 'Airtime', 'display_order' => 1],
            ['name' => 'DATA', 'display_name' => 'Data Bundles', 'display_order' => 2],
            ['name' => 'ELECTRICITY', 'display_name' => 'Electricity', 'display_order' => 3],
            ['name' => 'CABLE_TV', 'display_name' => 'Cable TV', 'display_order' => 4],
            ['name' => 'BETTING', 'display_name' => 'Betting', 'display_order' => 5],
        ];

        foreach ($categories as $category) {
            BillCategory::firstOrCreate(
                ['name' => $category['name']],
                [
                    'display_name' => $category['display_name'],
                    'display_order' => $category['display_order'],
                    'status' => 'ACTIVE',
                ],
            );

            BillProduct::firstOrCreate(
                ['bill_provider_id' => $billProvider->id, 'code' => $category['name'].'-DEFAULT'],
                [
                    'category' => $category['name'],
                    'name' => $category['display_name'].' (default)',
                    'status' => 'ACTIVE',
                ],
            );
        }
    }
}

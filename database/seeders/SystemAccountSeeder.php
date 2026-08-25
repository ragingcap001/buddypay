<?php

namespace Database\Seeders;

use App\Domain\Ledger\Constants\SystemAccounts;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Models\LedgerAccount;
use Illuminate\Database\Seeder;

class SystemAccountSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SystemAccounts::all() as $code => $definition) {
            LedgerAccount::firstOrCreate(
                ['code' => $code],
                [
                    'type' => $definition['type']->value,
                    'name' => $definition['name'],
                    'currency' => config('ase.base_currency', 'NGN'),
                ],
            );
        }
    }
}

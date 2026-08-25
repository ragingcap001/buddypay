<?php

namespace App\Domain\Transactions\Support;

use Illuminate\Support\Str;

/**
 * Generates unique, human-sortable references for financial records.
 */
final class ReferenceGenerator
{
    public static function transaction(): string
    {
        return config('ase.reference_prefix', 'ASE').'_T_'.(string) Str::ulid();
    }

    public static function ledger(): string
    {
        return config('ase.reference_prefix', 'ASE').'_L_'.(string) Str::ulid();
    }

    public static function reservation(): string
    {
        return config('ase.reference_prefix', 'ASE').'_R_'.(string) Str::ulid();
    }

    public static function kycVerification(): string
    {
        return config('ase.reference_prefix', 'ASE').'_KYC_'.(string) Str::ulid();
    }
}

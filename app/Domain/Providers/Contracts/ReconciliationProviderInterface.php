<?php

namespace App\Domain\Providers\Contracts;

use Illuminate\Support\Carbon;

/**
 * Providers that expose their own transaction records for reconciliation.
 */
interface ReconciliationProviderInterface
{
    /**
     * Fetch provider-side records for the given window.
     *
     * Each record is an associative array with at least:
     *  - reference: internal transaction reference
     *  - provider_reference: provider's own reference
     *  - amount: integer minor units
     *  - status: SUCCESS | FAILURE
     *  - timestamp: \DateTimeInterface
     *
     * @return iterable<array<string, mixed>>
     */
    public function fetchRecords(Carbon $from, Carbon $to): iterable;
}

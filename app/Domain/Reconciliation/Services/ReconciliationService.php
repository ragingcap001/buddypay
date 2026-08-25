<?php

namespace App\Domain\Reconciliation\Services;

use App\Domain\Reconciliation\Enums\ReconciliationItemStatus;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Infrastructure\Providers\ProviderFactory;
use App\Models\ReconciliationBatch;
use App\Models\ReconciliationItem;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reconciles internal transaction records against provider records for a
 * window, categorising every exception:
 *
 *   MATCHED, MISSING_PROVIDER_RECORD, MISSING_INTERNAL_RECORD,
 *   AMOUNT_MISMATCH, STATUS_MISMATCH, DUPLICATE_PROVIDER_TRANSACTION,
 *   UNRESOLVED
 */
final class ReconciliationService
{
    public function __construct(private readonly ProviderFactory $factory)
    {
    }

    public function runBatch(string $providerName, Carbon $from, Carbon $to): ReconciliationBatch
    {
        $batch = ReconciliationBatch::create([
            'provider_name' => $providerName,
            'from' => $from,
            'to' => $to,
            'status' => ReconciliationBatch::STATUS_RUNNING,
        ]);

        try {
            $records = [];

            foreach ($this->factory->makeReconciliationProvider($providerName)->fetchRecords($from, $to) as $record) {
                $records[] = $record;
            }

            $internal = Transaction::where('provider', $providerName)
                ->whereBetween('created_at', [$from, $to])
                ->get()
                ->keyBy('reference');

            $matched = 0;
            $exceptions = 0;
            $seenProviderReferences = [];
            $reconciledInternalReferences = [];

            foreach ($records as $record) {
                $reference = (string) ($record['reference'] ?? '');
                $providerReference = (string) ($record['provider_reference'] ?? '');
                $amount = (int) ($record['amount'] ?? 0);
                $providerStatus = (string) ($record['status'] ?? '');

                if ($providerReference !== '' && isset($seenProviderReferences[$providerReference])) {
                    ReconciliationItem::create([
                        'batch_id' => $batch->id,
                        'reference' => $reference,
                        'provider_reference' => $providerReference,
                        'provider_amount' => $amount,
                        'status' => ReconciliationItemStatus::DuplicateProviderTransaction->value,
                    ]);
                    $exceptions++;
                    continue;
                }

                if ($providerReference !== '') {
                    $seenProviderReferences[$providerReference] = true;
                }

                $transaction = $internal->get($reference);

                if ($transaction === null) {
                    ReconciliationItem::create([
                        'batch_id' => $batch->id,
                        'reference' => $reference,
                        'provider_reference' => $providerReference,
                        'provider_amount' => $amount,
                        'status' => ReconciliationItemStatus::MissingInternalRecord->value,
                    ]);
                    $exceptions++;
                    continue;
                }

                $reconciledInternalReferences[] = $reference;

                $expectedStatus = match (TransactionStatus::from($transaction->status)) {
                    TransactionStatus::Success, TransactionStatus::Completed => 'SUCCESS',
                    TransactionStatus::Failed => 'FAILURE',
                    default => null,
                };

                if ($expectedStatus === null) {
                    ReconciliationItem::create([
                        'batch_id' => $batch->id,
                        'reference' => $reference,
                        'provider_reference' => $providerReference,
                        'internal_amount' => (int) $transaction->amount,
                        'provider_amount' => $amount,
                        'status' => ReconciliationItemStatus::Unresolved->value,
                        'details' => ['internal_status' => $transaction->status],
                    ]);
                    $exceptions++;
                    continue;
                }

                if ((int) $transaction->amount !== $amount) {
                    ReconciliationItem::create([
                        'batch_id' => $batch->id,
                        'reference' => $reference,
                        'provider_reference' => $providerReference,
                        'internal_amount' => (int) $transaction->amount,
                        'provider_amount' => $amount,
                        'status' => ReconciliationItemStatus::AmountMismatch->value,
                    ]);
                    $exceptions++;
                    continue;
                }

                if ($providerStatus !== $expectedStatus) {
                    ReconciliationItem::create([
                        'batch_id' => $batch->id,
                        'reference' => $reference,
                        'provider_reference' => $providerReference,
                        'internal_amount' => (int) $transaction->amount,
                        'provider_amount' => $amount,
                        'status' => ReconciliationItemStatus::StatusMismatch->value,
                        'details' => ['expected' => $expectedStatus, 'provider' => $providerStatus],
                    ]);
                    $exceptions++;
                    continue;
                }

                ReconciliationItem::create([
                    'batch_id' => $batch->id,
                    'reference' => $reference,
                    'provider_reference' => $providerReference,
                    'internal_amount' => (int) $transaction->amount,
                    'provider_amount' => $amount,
                    'status' => ReconciliationItemStatus::Matched->value,
                ]);
                $matched++;
            }

            // Internal records with no provider record at all.
            foreach ($internal->keys() as $reference) {
                if (in_array($reference, $reconciledInternalReferences, true)) {
                    continue;
                }

                $transaction = $internal[$reference];

                ReconciliationItem::create([
                    'batch_id' => $batch->id,
                    'reference' => $reference,
                    'internal_amount' => (int) $transaction->amount,
                    'status' => ReconciliationItemStatus::MissingProviderRecord->value,
                ]);
                $exceptions++;
            }

            $batch->update([
                'status' => ReconciliationBatch::STATUS_COMPLETED,
                'total_items' => $matched + $exceptions,
                'matched' => $matched,
                'exceptions' => $exceptions,
            ]);
        } catch (Throwable $e) {
            $batch->update([
                'status' => ReconciliationBatch::STATUS_FAILED,
                'summary' => ['error' => $e->getMessage()],
            ]);
        }

        return $batch->refresh();
    }
}

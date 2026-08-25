<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only state-transition log for a transaction.
 */
class TransactionEvent extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'transaction_id',
        'from_status',
        'to_status',
        'reason',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}

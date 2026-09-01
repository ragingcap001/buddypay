<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'reference',
        'user_id',
        'type',
        'status',
        'amount',
        'fee',
        'currency',
        'metadata',
        'provider',
        'provider_reference',
        'reservation_id',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'fee' => 'integer',
            'metadata' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(WalletReservation::class, 'reservation_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TransactionEvent::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ProviderAttempt::class);
    }
}

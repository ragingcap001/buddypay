<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletReservation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'wallet_id',
        'reference',
        'amount',
        'status',
        'expires_at',
        'committed_at',
        'released_at',
        'release_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'expires_at' => 'datetime',
            'committed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiftCardRedemption extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'transaction_id',
        'card_number',
        'pin_code',
        'redemption_url',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'card_number',
        'pin_code',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'card_number' => 'encrypted',
            'pin_code' => 'encrypted',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}

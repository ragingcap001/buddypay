<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskAssessment extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'transaction_id',
        'transaction_type',
        'amount',
        'kyc_tier',
        'outcome',
        'signals',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'kyc_tier' => 'integer',
            'signals' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

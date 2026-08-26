<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycVerification extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'kyc_profile_id',
        'reference',
        'type',
        'status',
        'input_hash',
        'provider_response',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_response' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(KycProfile::class, 'kyc_profile_id');
    }
}

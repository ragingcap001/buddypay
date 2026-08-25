<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycDocument extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'kyc_profile_id',
        'type',
        'storage_path',
        'status',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(KycProfile::class, 'kyc_profile_id');
    }
}

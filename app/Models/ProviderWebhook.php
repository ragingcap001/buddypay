<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderWebhook extends Model
{
    public const STATUS_RECEIVED = 'RECEIVED';
    public const STATUS_PROCESSED = 'PROCESSED';
    public const STATUS_FAILED = 'FAILED';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider_id',
        'event_type',
        'provider_event_id',
        'raw_payload',
        'status',
        'error',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}

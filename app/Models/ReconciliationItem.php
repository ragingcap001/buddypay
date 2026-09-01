<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'batch_id',
        'reference',
        'provider_reference',
        'internal_amount',
        'provider_amount',
        'status',
        'details',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'internal_amount' => 'integer',
            'provider_amount' => 'integer',
            'details' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ReconciliationBatch::class);
    }
}

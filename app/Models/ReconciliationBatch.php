<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class ReconciliationBatch extends Model
{
    public const STATUS_RUNNING = 'RUNNING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider_name',
        'from',
        'to',
        'status',
        'total_items',
        'matched',
        'exceptions',
        'summary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from' => 'datetime',
            'to' => 'datetime',
            'total_items' => 'integer',
            'matched' => 'integer',
            'exceptions' => 'integer',
            'summary' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReconciliationItem::class);
    }
}

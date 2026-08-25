<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillProduct extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'bill_provider_id',
        'category',
        'name',
        'code',
        'status',
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

    public function billProvider(): BelongsTo
    {
        return $this->belongsTo(BillProvider::class);
    }
}

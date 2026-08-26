<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerTransaction extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'reference',
        'description',
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

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerAccount extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'type',
        'name',
        'currency',
        'status',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}

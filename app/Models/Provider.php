<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_DISABLED = 'DISABLED';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'type',
        'display_name',
        'base_url',
        'status',
        'priority',
        'config',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'config' => 'array',
        ];
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ProviderAttempt::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(BillProvider::class);
    }
}

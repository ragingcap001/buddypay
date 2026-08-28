<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton row (always id=1) of public product feature flags + socials.
 * See the platform_preferences migration for why this is separate from
 * app_config.
 */
class Preference extends Model
{
    protected $table = 'platform_preferences';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'features',
        'socials',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'features' => 'array',
            'socials' => 'array',
        ];
    }
}

<?php

namespace App\Models;

/**
 * A single runtime configuration value, editable from the admin
 * dashboard. Values are encrypted at rest. A row overrides the
 * environment variable of the same key (see config/app_config.php);
 * deleting the row falls back to the env/default value.
 */
class AppConfig extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'group',
        'value',
        'is_secret',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'is_secret' => 'boolean',
        ];
    }

    public function updatedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

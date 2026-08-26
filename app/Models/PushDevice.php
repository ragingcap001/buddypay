<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A push-notification device registration (FCM token). Delivery covers
 * both Apple (iOS, via FCM's APNs relay) and Google (Android) from the
 * same store — the platform field is informational.
 */
class PushDevice extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'platform',
        'token',
        'name',
        'active',
        'last_used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

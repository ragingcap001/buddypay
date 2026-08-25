<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtpChallenge extends Model
{
    public const PURPOSE_REGISTER = 'REGISTER';
    public const PURPOSE_LOGIN = 'LOGIN';
    public const PURPOSE_PASSWORD_RESET = 'PASSWORD_RESET';
    public const PURPOSE_PIN_CHANGE = 'PIN_CHANGE';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'purpose',
        'code_hash',
        'attempts',
        'expires_at',
        'consumed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

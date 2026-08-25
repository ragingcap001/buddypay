<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Wallet extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'currency',
        'control_balance',
        'reserved_balance',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'control_balance' => 'integer',
            'reserved_balance' => 'integer',
        ];
    }

    public function user(): HasOne
    {
        return $this->belongsTo(User::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(WalletReservation::class);
    }

    public function availableBalance(): int
    {
        return (int) $this->control_balance - (int) $this->reserved_balance;
    }
}

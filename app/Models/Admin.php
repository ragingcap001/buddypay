<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Internal staff account for the Filament panel.
 *
 * Kept separate from the customer-facing App\Models\User — see the
 * create_admins_table migration for why.
 */
class Admin extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Every row in this table is a staff account by definition, so
     * existence is the authorisation. Narrow this (roles/permissions)
     * when the team needs more than one privilege level.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}

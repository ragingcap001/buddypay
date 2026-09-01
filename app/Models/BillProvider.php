<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bill-service offering of a registered provider (catalog table).
 */
class BillProvider extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider_id',
        'display_name',
        'description',
        'status',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(BillProduct::class);
    }
}

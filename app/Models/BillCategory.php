<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
class BillCategory extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'display_name',
        'display_order',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
        ];
    }
}

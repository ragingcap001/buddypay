<?php

namespace App\Models;

class OutboxEvent extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'payload',
        'status',
        'attempts',
        'dispatched_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'dispatched_at' => 'datetime',
        ];
    }
}

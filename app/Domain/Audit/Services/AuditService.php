<?php

namespace App\Domain\Audit\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable audit logging for security- and finance-sensitive actions.
 */
final class AuditService
{
    /**
     * $actor is any Eloquent model — the row stores a morph class + key, so
     * both a customer (App\Models\User, via the admin API) and a staff
     * account (App\Models\Admin, via the Filament panel) attribute correctly.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function log(string $action, ?Model $subject = null, ?Model $actor = null, array $metadata = []): AuditLog
    {
        return AuditLog::create([
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'ip_address' => request()?->ip(),
            'metadata' => $metadata,
        ]);
    }
}

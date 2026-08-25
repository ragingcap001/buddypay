<?php

namespace App\Domain\Audit\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable audit logging for security- and finance-sensitive actions.
 */
final class AuditService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(string $action, ?Model $subject = null, ?User $actor = null, array $metadata = []): AuditLog
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

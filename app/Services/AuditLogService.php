<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogService
{
    /** @param array<string, mixed>|null $metadata */
    public function record(?User $actor, string $action, string $severity, Request $request, ?array $metadata = null): void
    {
        AuditLog::query()->create([
            'actor_user_id' => $actor?->id,
            'clinic_id' => $actor?->clinic_id,
            'action' => $action,
            'severity' => $severity,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
        ]);
    }
}

<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Request $request,
    ) {}

    public function log(
        string $action,
        ?Model $entity = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?User $user = null,
        ?int $tenantId = null,
    ): AuditLog {
        $user ??= auth()->user();

        return AuditLog::create([
            'tenant_id' => $tenantId ?? $this->context->tenantId(),
            'location_id' => $this->context->locationId(),
            'user_id' => $user?->id,
            'impersonator_id' => session('impersonator_id'),
            'action' => $action,
            'entity_type' => $entity ? $entity::class : null,
            'entity_id' => $entity?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata,
            'ip_address' => $this->anonymizeIp($this->request->ip()),
            'user_agent' => substr((string) $this->request->userAgent(), 0, 255) ?: null,
            'created_at' => now(),
        ]);
    }

    /**
     * GDPR data minimization: zero the last IPv4 octet / truncate IPv6.
     */
    private function anonymizeIp(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        if (str_contains($ip, ':')) {
            $parts = explode(':', $ip);

            return implode(':', array_slice($parts, 0, 4)).'::';
        }

        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            $parts[3] = '0';

            return implode('.', $parts);
        }

        return null;
    }
}

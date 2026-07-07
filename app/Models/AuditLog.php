<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * No BelongsToTenant trait on purpose: audit logs are written for both
 * tenant-scoped and SaaS-level actions; reads are explicitly filtered.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'location_id', 'user_id', 'impersonator_id',
        'action', 'entity_type', 'entity_id',
        'old_values', 'new_values', 'metadata',
        'ip_address', 'user_agent', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Resolve old/new values into readable per-field diff rows:
     * [['field' => 'status', 'from' => 'confirmed', 'to' => 'seated'], …].
     * Unchanged fields are dropped; values are formatted for display.
     *
     * @return array<int, array{field: string, from: ?string, to: ?string}>
     */
    public function fieldChanges(): array
    {
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];
        $rows = [];

        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $field) {
            $from = array_key_exists($field, $old) ? self::formatValue($old[$field]) : null;
            $to = array_key_exists($field, $new) ? self::formatValue($new[$field]) : null;
            if ($from === $to) {
                continue;
            }
            $rows[] = ['field' => (string) $field, 'from' => $from, 'to' => $to];
        }

        return $rows;
    }

    private static function formatValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'ja' : 'nein';
        }
        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE);

            return $json === false ? '[…]' : Str::limit($json, 80);
        }

        return Str::limit((string) $value, 80);
    }
}

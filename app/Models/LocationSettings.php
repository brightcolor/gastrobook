<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationSettings extends Model
{
    use BelongsToTenant;

    protected $table = 'location_settings';

    protected $fillable = [
        'tenant_id', 'location_id',
        'slot_interval_minutes', 'default_duration_minutes', 'duration_rules', 'buffer_minutes',
        'min_lead_minutes', 'max_advance_days', 'min_party_online', 'max_party_online',
        'auto_confirm', 'request_only', 'capacity_mode', 'max_covers_per_slot',
        'waitlist_enabled', 'walkins_enabled',
        'cancellation_deadline_minutes', 'modification_deadline_minutes',
        'field_rules', 'reminder_enabled', 'reminder_hours_before', 'sms_reminder_enabled',
        'gap_optimization_enabled',
        'feedback_enabled', 'feedback_hours_after', 'feedback_external_url',
        'feedback_redirect_min_score', 'settings',
    ];

    protected $attributes = [
        'slot_interval_minutes' => 30,
        'default_duration_minutes' => 120,
        'buffer_minutes' => 0,
        'min_lead_minutes' => 60,
        'max_advance_days' => 90,
        'min_party_online' => 1,
        'max_party_online' => 8,
        'auto_confirm' => true,
        'request_only' => false,
        'capacity_mode' => 'table',
        'waitlist_enabled' => true,
        'walkins_enabled' => true,
        'cancellation_deadline_minutes' => 120,
        'modification_deadline_minutes' => 120,
        'reminder_enabled' => true,
        'reminder_hours_before' => 24,
        'sms_reminder_enabled' => false,
        'gap_optimization_enabled' => false,
        'feedback_enabled' => true,
        'feedback_hours_after' => 18,
        'feedback_redirect_min_score' => 4,
    ];

    protected function casts(): array
    {
        return [
            'duration_rules' => 'array',
            'field_rules' => 'array',
            'settings' => 'array',
            'auto_confirm' => 'boolean',
            'request_only' => 'boolean',
            'waitlist_enabled' => 'boolean',
            'walkins_enabled' => 'boolean',
            'reminder_enabled' => 'boolean',
            'sms_reminder_enabled' => 'boolean',
            'gap_optimization_enabled' => 'boolean',
            'feedback_enabled' => 'boolean',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Duration in minutes for a given party size and local start time.
     * duration_rules: [{"min_party":1,"max_party":4,"duration":90}, ...]
     */
    public function durationFor(int $partySize): int
    {
        foreach ($this->duration_rules ?? [] as $rule) {
            $min = $rule['min_party'] ?? 1;
            $max = $rule['max_party'] ?? 999;
            if ($partySize >= $min && $partySize <= $max && isset($rule['duration'])) {
                return (int) $rule['duration'];
            }
        }

        return (int) $this->default_duration_minutes;
    }

    /**
     * Field rule for the public widget: 'hidden' | 'optional' | 'required'.
     */
    public function fieldRule(string $field): string
    {
        $defaults = [
            'email' => 'required',
            'phone' => 'required',
            'address' => 'hidden',
            'birthday' => 'hidden',
            'occasion' => 'optional',
            'note' => 'optional',
            'allergies' => 'optional',
        ];

        return $this->field_rules[$field] ?? $defaults[$field] ?? 'optional';
    }
}

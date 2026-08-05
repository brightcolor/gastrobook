<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaign extends Model
{
    use BelongsToTenant;

    public const TYPES = ['birthday', 'rebooking', 'winback'];

    protected $fillable = [
        'tenant_id', 'location_id', 'type', 'name', 'is_active',
        'offset_days', 'min_visits', 'subject', 'body', 'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'offset_days' => 'integer',
            'min_visits' => 'integer',
            'last_run_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<MarketingSend, $this> */
    public function sends(): HasMany
    {
        return $this->hasMany(MarketingSend::class);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'birthday' => 'Geburtstagsgruß',
            'rebooking' => 'Erinnerung nach dem Besuch',
            'winback' => 'Lange nicht gesehen',
            default => $this->type,
        };
    }

    /** Plain-language description of when this campaign fires. */
    public function timingLabel(): string
    {
        $days = (int) $this->offset_days;

        return match ($this->type) {
            'birthday' => $days === 0 ? 'am Geburtstag' : $days.' Tage vor dem Geburtstag',
            'rebooking' => $days.' Tage nach dem Besuch',
            'winback' => 'wenn seit '.$days.' Tagen kein Besuch mehr war',
            default => '',
        };
    }
}

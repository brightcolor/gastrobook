<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'key', 'name', 'price_monthly_minor', 'currency',
        'limits', 'features', 'trial_days', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Gewährt dieser Tarif eine Testfrist? Nur dann unterliegt ein Betrieb
     * der Trial-Sperre. Bezahlte Tarife (trial_days 0) haben keine Frist –
     * ein Betrieb darauf darf nie wegen einer abgelaufenen Testfrist
     * ausgesperrt werden.
     */
    public function grantsTrial(): bool
    {
        return (int) $this->trial_days > 0;
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }
}

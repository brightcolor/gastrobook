<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class FeedbackRequest extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'location_id', 'reservation_id', 'token', 'sent_at', 'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (FeedbackRequest $request) {
            $request->token = $request->token ?: Str::random(48);
        });
    }

    /**
     * Delete old, never-answered feedback requests so the table doesn't grow
     * unbounded. Requests WITH a response are kept — the response
     * (score/comment) cascades on delete and holds the valuable feedback for
     * reports, so we never touch answered ones.
     */
    public static function pruneUnanswered(int $months = 6): int
    {
        return static::withoutGlobalScopes()
            ->whereNull('responded_at')
            ->whereDoesntHave('response')
            ->where('created_at', '<', now()->subMonths($months))
            ->delete();
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function response(): HasOne
    {
        return $this->hasOne(FeedbackResponse::class);
    }
}

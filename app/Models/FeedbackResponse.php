<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedbackResponse extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'location_id', 'feedback_request_id',
        'score', 'comment', 'locale', 'redirected_external',
    ];

    protected function casts(): array
    {
        return [
            'redirected_external' => 'boolean',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(FeedbackRequest::class, 'feedback_request_id');
    }
}

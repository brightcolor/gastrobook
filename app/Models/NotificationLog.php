<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'location_id', 'reservation_id', 'channel',
        'template_key', 'recipient', 'subject', 'status', 'error',
    ];
}

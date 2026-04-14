<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_type', 'source_service',
        'recipient_email', 'recipient_user_id',
        'notification_class', 'subject',
        'status', 'error_message', 'attempt',
        'event_payload', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'event_payload' => 'array',
            'sent_at'       => 'datetime',
        ];
    }
}
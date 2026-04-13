<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $keyType    = 'string';
    public    $incrementing = false;

    protected $fillable = [
        'user_id',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'preferred_language',
        'marketing_emails',
        'order_notifications',
    ];

    protected function casts(): array
    {
        return [
            'marketing_emails'     => 'boolean',
            'order_notifications'  => 'boolean',
        ];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
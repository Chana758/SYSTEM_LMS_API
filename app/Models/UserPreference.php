<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPreference extends Model
{
    protected $fillable = [
        'user_id',
        'dark_mode',
        'theme',
        'language',
        'email_notifications',
        'sms_notifications',
        'push_notifications',
        'overdue_alerts',
        'reservation_alerts',
        'promotional_alerts',
    ];

    protected $casts = [
        'dark_mode'            => 'boolean',
        'email_notifications'  => 'boolean',
        'sms_notifications'    => 'boolean',
        'push_notifications'   => 'boolean',
        'overdue_alerts'       => 'boolean',
        'reservation_alerts'   => 'boolean',
        'promotional_alerts'   => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
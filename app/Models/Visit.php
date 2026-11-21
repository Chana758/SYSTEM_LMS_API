<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Visit extends Model
{
    protected $fillable = [
        'user_id', 'member_id', 'visitor_type', 'guest_name', 'guest_phone', 'guest_purpose',
        'check_in_at', 'check_out_at', 'check_in_method', 'check_out_method',
        'kiosk_device_id', 'door_opened_at', 'created_by',
    ];

    protected $casts = [
        'check_in_at'    => 'datetime',
        'check_out_at'   => 'datetime',
        'door_opened_at' => 'datetime',
    ];

    protected $appends = ['minutes'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function kioskDevice(): BelongsTo
    {
        return $this->belongsTo(KioskDevice::class);
    }

    /** Minutes inside; still counting while the visit is open. */
    public function getMinutesAttribute(): ?int
    {
        if (! $this->check_in_at) {
            return null;
        }

        return (int) $this->check_in_at->diffInMinutes($this->check_out_at ?? now());
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('check_out_at');
    }

    public function scopeToday($query)
    {
        return $query->where('check_in_at', '>=', now()->startOfDay());
    }
}

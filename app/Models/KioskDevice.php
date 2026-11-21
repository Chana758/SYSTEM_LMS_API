<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KioskDevice extends Model
{
    protected $fillable = [
        'user_id', 'name', 'location', 'mode', 'door_enabled', 'door_pulse_ms',
    ];

    protected $hidden = ['door_key_hash'];

    protected $casts = [
        'door_enabled'      => 'boolean',
        'door_pulse_ms'     => 'integer',
        'last_seen_at'      => 'datetime',
        'door_last_seen_at' => 'datetime',
    ];

    protected $appends = ['has_door_key', 'online', 'door_online'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function getHasDoorKeyAttribute(): bool
    {
        return (bool) $this->door_key_hash;
    }

    /** The kiosk screen pings every minute. */
    public function getOnlineAttribute(): bool
    {
        return $this->last_seen_at && $this->last_seen_at->gt(now()->subMinutes(3));
    }

    /** The door controller polls every second. */
    public function getDoorOnlineAttribute(): bool
    {
        return $this->door_last_seen_at && $this->door_last_seen_at->gt(now()->subSeconds(30));
    }
}

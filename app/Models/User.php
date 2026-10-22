<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'avatar',
        'language_preference', 'role_id', 'status',
        'qr_login_token', 'qr_login_token_expires_at',
    ];

    protected $hidden = [
        'password', 'remember_token', 'qr_login_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'qr_login_token_expires_at' => 'datetime',
    ];

    // Always include has_qr_card in serialized output (the token itself stays hidden)
    protected $appends = ['has_qr_card'];

    /** True if the user has a QR login card that has not expired. */
    public function getHasQrCardAttribute(): bool
    {
        return ! is_null($this->qr_login_token)
            && $this->qr_login_token_expires_at
            && ! $this->qr_login_token_expires_at->isPast();
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function member()
    {
        return $this->hasOne(Member::class);
    }

    public function librarian()
    {
        return $this->hasOne(Librarian::class);
    }

    public function preference()
    {
        return $this->hasOne(UserPreference::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }
}
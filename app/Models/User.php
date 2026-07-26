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

    /**
     * NEW — always include this derived boolean when a User is
     * serialized (directly, or nested via ->load('user') on Member/
     * Librarian). qr_login_token itself stays hidden/hashed, but the
     * frontend needs SOME way to know "has this person's card been
     * issued yet?" without ever seeing the token itself.
     */
    protected $appends = ['has_qr_card'];

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
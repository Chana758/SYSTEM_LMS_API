<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar',
        'language_preference',
        'role_id',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    // Many Users -> One Role
    // Foreign Key: role_id
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    // One User -> One Member
    public function member()
    {
        return $this->hasOne(Member::class);
    }

    // One User -> One Librarian
    public function librarian()
    {
        return $this->hasOne(Librarian::class);
    }

    // One User -> One UserPreference (added)
    public function preference()
    {
        return $this->hasOne(UserPreference::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }
}

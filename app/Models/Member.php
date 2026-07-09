<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Member extends Model
{
    use HasFactory,SoftDeletes;
    // Fields allowed for Mass Assignment
    protected $fillable = [
        'user_id',
        'membership_no',
        'identity_card_no',
        'emergency_contact',
        'membership_type',
        'max_borrow_limit',
        'address',
        'join_date',
        'expiry_date',
        'status',
        'remarks',
    ];

    // The attributes that should be cast.
    protected $casts = [
        'join_date' => 'date',
        'expiry_date' => 'date',
    ];
    // Many Members -> One User
    // Foreign Key: user_id
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

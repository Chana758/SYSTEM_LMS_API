<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Member extends Model
{
    use HasFactory, SoftDeletes;

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

    protected $casts = [
        'join_date'   => 'date',
        'expiry_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * NEW — needed by ReportController@user for per-member borrow
     * counts (total_borrows / active_borrows).
     */
    public function borrows(): HasMany
    {
        return $this->hasMany(BorrowTransaction::class, 'member_id');
    }

    /**
     * NEW — Fine has no member_id/user_id of its own; it only reaches
     * a member through borrow_id. hasManyThrough bridges that gap so
     * ReportController@user can do withSum('fines', 'amount') directly.
     */
    public function fines(): HasManyThrough
    {
        return $this->hasManyThrough(
            Fine::class,
            BorrowTransaction::class,
            'member_id',  // FK on borrow_transactions pointing to members.id
            'borrow_id',  // FK on fines pointing to borrow_transactions.id
            'id',         // local key on members
            'id'          // local key on borrow_transactions
        );
    }
}
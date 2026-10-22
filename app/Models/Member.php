<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use App\Models\MembershipType;
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

    public function borrows(): HasMany
    {
        return $this->hasMany(BorrowTransaction::class, 'member_id');
    }

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
    
    public function loanRules(): array
{
    $type = MembershipType::where('code', $this->membership_type)->first();
    $d = MembershipType::defaults();

    return [
        'loan_days'    => $type?->loan_days    ?? $d['loan_days'],
        'max_books'    => $type?->max_books    ?? $d['max_books'],
        'max_renewals' => $type?->max_renewals ?? $d['max_renewals'],
        'renew_days'   => $type?->renew_days   ?? $d['renew_days'],
        'fine_per_day' => $type?->fine_per_day ?? $d['fine_per_day'],
    ];
}
}
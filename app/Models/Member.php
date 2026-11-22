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
        'source',
        'membership_no',
        'student_id',
        'has_school_card',
        'name_en',
        'gender',
        'date_of_birth',
        'class_name',
        'deposit_amount',
        'deposit_status',
        'deposit_paid_at',
        'deposit_deducted',
        'deposit_refunded',
        'deposit_refunded_at',
        'identity_card_no',
        'id_document',
        'emergency_contact',
        'membership_type',
        'max_borrow_limit',
        'address',
        'join_date',
        'expiry_date',
        'status',
        'remarks',
    ];

    // The ID photo is only served to staff through its own endpoint.
    protected $hidden = ['id_document'];

    protected $appends = ['has_id_document'];

    public function getHasIdDocumentAttribute(): bool
    {
        return ! empty($this->attributes['id_document'] ?? null);
    }

    protected $casts = [
        'join_date'   => 'date:Y-m-d',
        'expiry_date' => 'date:Y-m-d',
        'date_of_birth' => 'date:Y-m-d',
        'has_school_card' => 'boolean',
        'deposit_amount' => 'decimal:2',
        'deposit_deducted' => 'decimal:2',
        'deposit_refunded' => 'decimal:2',
        'deposit_paid_at' => 'datetime',
        'deposit_refunded_at' => 'datetime',
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
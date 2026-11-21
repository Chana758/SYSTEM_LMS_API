<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One KHQR (or cash) payment for a fine, deposit or e-book rental (B5.1). */
class Payment extends Model
{
    public const PENDING = 'pending';
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const EXPIRED = 'expired';
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'tran_id', 'user_id', 'payable_type', 'payable_id', 'purpose', 'amount', 'currency', 'amount_khr', 'exchange_rate', 'cash_received_khr', 'cash_received_usd', 'change_khr',
        'method', 'provider', 'status', 'qr_string', 'apv', 'provider_response',
        'expires_at', 'paid_at', 'last_checked_at', 'created_by',
    ];

    protected $hidden = ['provider_response'];

    protected $casts = [
        'amount'            => 'decimal:2',
        'amount_khr'        => 'decimal:2',
        'exchange_rate'     => 'decimal:2',
        'cash_received_khr' => 'decimal:2',
        'cash_received_usd' => 'decimal:2',
        'change_khr'        => 'decimal:2',
        'provider_response' => 'array',
        'expires_at'        => 'datetime',
        'paid_at'           => 'datetime',
        'last_checked_at'   => 'datetime',
    ];

    public function payable()
    {
        return $this->morphTo();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use HasFactory;

    public const PENDING   = 'pending';
    public const READY     = 'ready';
    public const FULFILLED = 'fulfilled';
    public const CANCELLED = 'cancelled';
    public const EXPIRED   = 'expired';

    public const STATUSES = [
        self::PENDING, self::READY, self::FULFILLED, self::CANCELLED, self::EXPIRED,
    ];

    /** Statuses that still occupy a place in the queue. */
    public const ACTIVE = [self::PENDING, self::READY];

    protected $fillable = [
    'book_id',
    'member_id',
    'reserved_date',
    'expire_date',
    'status',
    'priority_order',
    'ready_at',
    'fulfilled_at',
    'cancelled_at',
    'cancelled_by',
    'expired_at',
];

protected $casts = [
    'reserved_date' => 'date',
    'expire_date'   => 'date',
    'ready_at'      => 'datetime',
    'fulfilled_at'  => 'datetime',
    'cancelled_at'  => 'datetime',
    'expired_at'    => 'datetime',
];

    // Relations

    public function book()
    {
        return $this->belongsTo(Book::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    // Scopes 

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE);
    }

    public function scopeHistory(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::ACTIVE);
    }

    public function scopeForBook(Builder $query, int $bookId): Builder
    {
        return $query->where('book_id', $bookId);
    }

    // Helpers 

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }
}
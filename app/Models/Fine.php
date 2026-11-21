<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Fine extends Model
{
    use HasFactory;

    protected $fillable = [
        'borrow_id',
        'amount',      // Riel (FIN1)
        'reason',
        'status',
        'paid_at',
        'payment_method',
        'notes',
        'waived_by',
        'waived_at',
    ];

    protected $casts = [
        'amount'    => 'decimal:2',
        'paid_at'   => 'datetime',
        'waived_at' => 'datetime',
    ];

    public function borrow()
    {
        return $this->belongsTo(BorrowTransaction::class, 'borrow_id');
    }

    public function payments()
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function waivedBy()
    {
        return $this->belongsTo(User::class, 'waived_by');
    }
}

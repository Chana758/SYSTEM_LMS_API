<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Fine extends Model
{
    use HasFactory;

    protected $fillable = [
        'borrow_id',
        'amount',
        'reason',
        'status',
        'paid_at',
        'payment_method',
        'notes',
    ];

    protected $casts = [
        'amount'  => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function borrow()
    {
        return $this->belongsTo(BorrowTransaction::class, 'borrow_id');
    }
}
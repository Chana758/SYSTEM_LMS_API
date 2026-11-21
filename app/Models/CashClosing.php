<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** End-of-day count of one staff member's cash drawer (once per day, cannot be edited). */
class CashClosing extends Model
{
    protected $fillable = [
        'user_id', 'date', 'payments_count', 'expected_khr', 'expected_usd',
        'counted_khr', 'counted_usd', 'diff_khr', 'diff_usd', 'note',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'expected_khr' => 'decimal:2', 'expected_usd' => 'decimal:2',
        'counted_khr' => 'decimal:2', 'counted_usd' => 'decimal:2',
        'diff_khr' => 'decimal:2', 'diff_usd' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

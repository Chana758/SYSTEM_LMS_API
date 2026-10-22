<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BorrowTransaction extends Model
{
    use HasFactory;

    protected $table = 'borrow_transactions';

    protected $fillable = [
        'book_copy_id',
        'member_id',
        'librarian_id',
        'borrow_date',
        'due_date',
        'return_date',
        'status',
        'renewed_count',
        'notes',
    ];

    protected $casts = [
        'borrow_date'   => 'date',
        'due_date'      => 'date',
        'return_date'   => 'date',
        'renewed_count' => 'integer',
    ];

    public function bookCopy(): BelongsTo
    {
        return $this->belongsTo(BookCopy::class, 'book_copy_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function librarian(): BelongsTo
    {
        return $this->belongsTo(Librarian::class, 'librarian_id');
    }

    public function fines(): HasMany
    {
        return $this->hasMany(Fine::class, 'borrow_id');
    }
}
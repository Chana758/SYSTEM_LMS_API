<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowTransaction extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'borrow_transactions';

    /**
     * The attributes that are mass assignable.
     */
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
        'fine_amount',
        'fine_status',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected $casts = [
        'borrow_date' => 'date',
        'due_date'    => 'date',
        'return_date' => 'date',
    ];

    // --- Relationships ---

    public function bookCopy()
    {
        return $this->belongsTo(BookCopy::class, 'book_copy_id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function librarian()
    {
        return $this->belongsTo(Librarian::class, 'librarian_id');
    }

    // ★★★ ថ្មី — ត្រូវការសម្រាប់ BorrowController::index()/show() ដែលហៅ with(['fines'])
    public function fines()
    {
        return $this->hasMany(Fine::class, 'borrow_id');
    }
}
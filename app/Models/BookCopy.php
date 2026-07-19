<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BookCopy extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'book_id',
        'barcode',
        'status',
        'condition',
        'shelf_location',
        'acquired_date',
        'notes',
    ];

    /**
     * Relationship: A copy belongs to a specific book title.
     */
    public function book()
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * Relationship: A copy can have many borrow transactions.
     */
    public function transactions()
    {
        return $this->hasMany(BorrowTransaction::class, 'book_copy_id');
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Book extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'isbn',
        'author',
        'publisher',
        'category_id',
        'language',
        'publish_year',
        'edition',
        'pages',
        'description',
        'total_qty',
        'available_qty',
        'cover_image',
        'shelf_location',
        'price',
        'acquired_date',
        'status',
        'views_count',
        'rating_avg',
        'rating_count',
    ];

    /**
     * Get the category that owns the book.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the individual physical copies of this book.
     */
    public function bookCopies(): HasMany
    {
        return $this->hasMany(BookCopy::class);
    }

    /**
     * Get all borrow transactions for all copies of this book.
     * Uses HasManyThrough to aggregate data from BookCopy.
     */
    public function borrows(): HasManyThrough
    {
        return $this->hasManyThrough(BorrowTransaction::class, BookCopy::class);
    }

    /**
     * Get the reservations associated with this book.
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * Get the reviews for this book.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(BookReview::class);
    }

    // Ebook
    public function ebook()
    {
        return $this->hasOne(Ebook::class);
    }
}
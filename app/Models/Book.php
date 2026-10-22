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

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function bookCopies(): HasMany
    {
        return $this->hasMany(BookCopy::class);
    }

    public function borrows(): HasManyThrough
    {
        return $this->hasManyThrough(BorrowTransaction::class, BookCopy::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(BookReview::class);
    }

    public function ebook()
    {
        return $this->hasOne(Ebook::class);
    }

    /** Recount total_qty / available_qty from book_copies (soft-deleted copies excluded). */
    public function syncQty(): void
    {
        $this->forceFill([
            'total_qty'     => $this->bookCopies()->count(),
            'available_qty' => $this->bookCopies()->where('status', 'available')->count(),
        ])->save();
    }
}
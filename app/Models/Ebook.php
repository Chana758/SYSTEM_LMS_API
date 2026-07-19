<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ebook extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'book_id',
        'file_url',
        'format',
        'file_size',
        'download_count',
        'view_count',
        'is_downloadable',
        'access_type',
    ];

    protected $casts = [
        'is_downloadable' => 'boolean',
        'file_size'       => 'integer',
        'download_count'  => 'integer',
        'view_count'      => 'integer',
    ];

    public function book()
    {
        return $this->belongsTo(Book::class);
    }

    public function readingProgress()
    {
        return $this->hasMany(EbookReadingProgress::class);
    }

    public function favorites()
    {
        return $this->hasMany(EbookFavorite::class);
    }
}
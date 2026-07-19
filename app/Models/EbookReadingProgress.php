<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EbookReadingProgress extends Model
{
    protected $table = 'ebook_reading_progress';

    protected $fillable = [
        'user_id',
        'ebook_id',
        'last_page',
        'percentage',
        'last_read_at',
    ];

    protected $casts = [
        'percentage'   => 'decimal:2',
        'last_read_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ebook()
    {
        return $this->belongsTo(Ebook::class);
    }
}
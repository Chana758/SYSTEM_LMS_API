<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EbookFavorite extends Model
{
    protected $table = 'ebook_favorites';

    protected $fillable = ['user_id', 'ebook_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ebook()
    {
        return $this->belongsTo(Ebook::class);
    }
}
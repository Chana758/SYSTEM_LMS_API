<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Backup extends Model
{
    protected $fillable = [
        'performed_by',
        'file_name',
        'file_path',
        'file_size',
        'type',
        'status',
        'error_message',
    ];

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    use HasFactory;

    public const TYPE_BORROW  = 'borrow';
    public const TYPE_FINE    = 'fine';
    public const TYPE_USER    = 'user';
    public const TYPE_REVENUE = 'revenue';
    public const TYPE_STOCK   = 'stock';

    public const FORMAT_PDF   = 'pdf';
    public const FORMAT_EXCEL = 'excel';
    public const FORMAT_CSV   = 'csv';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'type', 'generated_by', 'date_from', 'date_to',
        'file_url', 'format', 'status',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to'   => 'date',
    ];

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
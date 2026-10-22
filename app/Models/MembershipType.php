<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MembershipType extends Model
{
    use HasFactory;

   protected $fillable = [
    'code', 'label', 'description', 'is_active',
    'loan_days', 'max_books', 'max_renewals', 'renew_days', 'fine_per_day',
];

protected $casts = [
    'is_active'    => 'boolean',
    'loan_days'    => 'integer',
    'max_books'    => 'integer',
    'max_renewals' => 'integer',
    'renew_days'   => 'integer',
    'fine_per_day' => 'integer',
];

/** Fallback used when a member has an unknown/legacy type code. */
public static function defaults(): array
{
    return ['loan_days' => 14, 'max_books' => 3, 'max_renewals' => 2, 'renew_days' => 7, 'fine_per_day' => 500];
}
}
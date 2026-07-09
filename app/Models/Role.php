<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    use HasFactory,SoftDeletes;
    // Fields allowed for Mass assignment
    protected $fillable = ['name'];

    // One-to-Many Relationship
    // One Role -> Many Users
    // Foreign Key: role_id (in users table)
    public function users()
    {
        return $this->hasMany(User::class);
    }
    
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoleChange extends Model
{
    protected $fillable = [
        'user_id',
        'changed_by',
        'from_role_id',
        'to_role_id',
        'note',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function fromRole()
    {
        return $this->belongsTo(Role::class, 'from_role_id');
    }

    public function toRole()
    {
        return $this->belongsTo(Role::class, 'to_role_id');
    }
}
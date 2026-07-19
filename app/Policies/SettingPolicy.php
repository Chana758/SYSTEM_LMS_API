<?php

namespace App\Policies;

use App\Models\User;

class SettingPolicy
{
    // Admin: full access | Librarian: view only | Member: no access
    public function viewAny(User $user)
    {
        return in_array($user->role->name, ['admin', 'librarian']);
    }

    public function view(User $user)
    {
        return in_array($user->role->name, ['admin', 'librarian']);
    }

    public function create(User $user)
    {
        return $user->role->name === 'admin';
    }

    public function update(User $user)
    {
        return $user->role->name === 'admin';
    }

    public function delete(User $user)
    {
        return $user->role->name === 'admin';
    }
}
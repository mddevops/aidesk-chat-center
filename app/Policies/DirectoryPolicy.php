<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class DirectoryPolicy
{
    public function view(User $user, Model $model): bool
    {
        return $user->is_active && (int) $user->organization_id === (int) $model->organization_id;
    }

    public function update(User $user, Model $model): bool
    {
        return $this->view($user, $model) && $user->hasPermission('directory.manage');
    }
}

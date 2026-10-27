<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Widget;

class WidgetPolicy
{
    public function create(User $user): bool
    {
        return $user->is_active && $user->organization_id !== null && $user->hasPermission('directory.manage');
    }

    public function update(User $user, Widget $widget): bool
    {
        return $this->create($user) && (int) $user->organization_id === (int) $widget->organization_id;
    }
}

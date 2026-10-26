<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('organizations.{organizationId}.operators.{userId}', function (User $user, string $organizationId, string $userId) {
    return ctype_digit($organizationId) && ctype_digit($userId) && $user->is_active && $user->hasPermission('chat.view')
        && (int) $user->organization_id === (int) $organizationId && $user->id === (int) $userId;
});

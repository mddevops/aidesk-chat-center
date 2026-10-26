<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $user->hasPermission('chat.view') && (int) $user->organization_id === (int) $conversation->organization_id;
    }

    public function reply(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation) && $user->hasPermission('chat.reply');
    }
}

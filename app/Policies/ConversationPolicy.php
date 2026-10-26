<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $user->hasPermission('chat.view') && (int) $user->organization_id === (int) $conversation->organization_id
            && ($user->hasPermission('chat.manage') || $conversation->department_id === null || (int) $conversation->department_id === (int) $user->department_id);
    }

    public function reply(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation) && $user->hasPermission('chat.reply')
            && ($user->hasPermission('chat.manage') || (int) $conversation->assigned_user_id === $user->id);
    }

    public function accept(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation) && $user->hasPermission('chat.reply');
    }

    public function assign(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation) && $user->hasPermission('chat.manage');
    }

    public function transition(User $user, Conversation $conversation): bool
    {
        return $this->reply($user, $conversation);
    }
}

<?php

namespace App\Chat;

use App\Models\Conversation;
use App\Models\ConversationRead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ConversationPayload
{
    public static function make(Conversation $conversation, User $user): array
    {
        $read = $conversation->getAttribute('last_read_sequence') ?? ConversationRead::where('conversation_id', $conversation->id)->where('user_id', $user->id)->value('last_read_sequence') ?? 0;
        $unread = $conversation->getAttribute('unread_count') ?? Message::where('conversation_id', $conversation->id)->where('sender_type', 'visitor')->where('kind', 'message')->where('sequence', '>', $read)->count();
        $gate = Gate::forUser($user);

        return [...$conversation->only('id', 'public_id', 'status', 'assigned_user_id', 'department_id', 'last_sequence', 'last_message_at', 'version'),
            'contact' => $conversation->contact->only('id', 'name', 'phone'),
            'channel' => $conversation->channel->only('id', 'name', 'kind'),
            'assigned_user' => $conversation->assignedUser?->only('id', 'name'),
            'department' => $conversation->department?->only('id', 'name'),
            'preview' => $conversation->latestMessage ? mb_substr($conversation->latestMessage->body, 0, 160) : '',
            'preview_kind' => $conversation->latestMessage?->kind,
            'last_read_sequence' => (int) $read, 'unread_count' => (int) $unread,
            'permissions' => ['reply' => $gate->allows('reply', $conversation) && $conversation->status !== 'closed', 'read' => $gate->allows('reply', $conversation), 'accept' => $gate->allows('accept', $conversation) && $conversation->status === 'pending' && $conversation->assigned_user_id === null, 'assign' => $gate->allows('assign', $conversation), 'transition' => $gate->allows('transition', $conversation)],
        ];
    }
}

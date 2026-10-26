<?php

namespace App\Chat;

use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;

class ChatPayload
{
    public static function message(Message $message): array
    {
        return [
            'id' => $message->id, 'sequence' => $message->sequence,
            'client_message_id' => $message->client_message_id,
            'sender_type' => $message->sender_type, 'operator_name' => $message->operator?->name, 'kind' => $message->kind,
            'body' => $message->body, 'delivery_status' => $message->delivery_status ?? 'stored',
            'created_at' => $message->created_at->toISOString(),
            'attachments' => $message->attachments->map(fn ($file) => self::attachment($file))->all(),
        ];
    }

    public static function attachment(Attachment $file): array
    {
        return $file->only(['id', 'name', 'mime', 'size']);
    }

    public static function visitorConversation(?Conversation $conversation): ?array
    {
        return $conversation ? ['id' => $conversation->public_id, 'status' => $conversation->status, 'last_sequence' => $conversation->last_public_sequence] : null;
    }
}

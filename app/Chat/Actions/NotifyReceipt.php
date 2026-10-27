<?php

namespace App\Chat\Actions;

use App\Jobs\PublishChatEvent;
use App\Models\ChatEvent;
use App\Models\Conversation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NotifyReceipt
{
    public static function record(Conversation $conversation): void
    {
        $event = ChatEvent::create(['event_id' => (string) Str::uuid(), 'event_type' => 'conversation.receipt_updated', 'conversation_id' => $conversation->id, 'message_id' => null, 'aggregate_version' => $conversation->version, 'available_at' => now()]);
        DB::afterCommit(function () use ($conversation, $event) {
            try {
                PublishChatEvent::dispatch((int) $conversation->organization_id, $event->id);
            } catch (\Throwable) {
                Log::warning('chat.outbox.enqueue_failed', ['event_id' => $event->event_id]);
            }
        });
    }
}

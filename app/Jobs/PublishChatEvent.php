<?php

namespace App\Jobs;

use App\Events\ChatMessageAvailable;
use App\Models\ChatEvent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class PublishChatEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public int $organizationId, public int $eventId) {}

    public function backoff(): array
    {
        return [5, 15, 60, 180];
    }

    public function handle(): void
    {
        $context = app(TenantContext::class);
        $previous = $context->id();
        $context->set($this->organizationId);
        try {
            DB::transaction(function () {
                $event = ChatEvent::whereKey($this->eventId)->lockForUpdate()->first();
                if (! $event || $event->published_at || $event->available_at->isFuture()) {
                    return;
                }
                $conversation = Conversation::findOrFail($event->conversation_id);
                $message = Message::findOrFail($event->message_id);
                $operators = User::where('organization_id', $this->organizationId)->where('is_active', true)->whereIn('role', ['owner', 'admin', 'supervisor', 'operator'])->pluck('id')->all();
                foreach (array_chunk($operators, 75) as $chunk) {
                    event(new ChatMessageAvailable($this->organizationId, $chunk, [
                        'event_id' => $event->event_id, 'schema_version' => 1, 'type' => $event->event_type,
                        'aggregate_id' => $conversation->public_id, 'aggregate_version' => $message->sequence,
                        'occurred_at' => $event->created_at->toISOString(),
                        'data' => ['conversation_id' => $conversation->id, 'message_id' => $message->id, 'sequence' => $message->sequence],
                    ]));
                }
                $event->published_at = now();
                $event->last_error_code = null;
                $event->save();
            }, 3);
        } catch (\Throwable $error) {
            $event = ChatEvent::whereKey($this->eventId)->whereNull('published_at')->first();
            if ($event) {
                $event->attempts++;
                $event->last_error_code = 'broadcast_failed';
                $event->available_at = now()->addSeconds(min(300, 5 * (2 ** min($event->attempts - 1, 6))));
                $event->save();
            }
            throw $error;
        } finally {
            $context->clear();
            if ($previous !== null) {
                $context->set($previous);
            }
        }
    }
}

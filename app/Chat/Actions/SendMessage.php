<?php

namespace App\Chat\Actions;

use App\Chat\ChatException;
use App\Jobs\PublishChatEvent;
use App\Models\Attachment;
use App\Models\ChatEvent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\VisitorSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SendMessage
{
    public function visitor(VisitorSession $session, array $data): array
    {
        return DB::transaction(function () use ($session, $data) {
            $session = VisitorSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->revoked_at || $session->expires_at->isPast()) {
                throw new ChatException('invalid_credential', 'Сессия посетителя недействительна.', 401);
            }
            if (! $session->contact_id) {
                throw new ChatException('identity_required', 'Перед сообщением укажите имя, телефон и согласие.', 403);
            }
            $conversation = Conversation::where('visitor_session_id', $session->id)->lockForUpdate()->first();
            $conversation ??= Conversation::create(['channel_id' => $session->channel_id, 'visitor_session_id' => $session->id, 'contact_id' => $session->contact_id]);

            return $this->persist($conversation, $data, 'visitor:'.$session->public_id, $session->id, null);
        }, 3);
    }

    public function operator(Conversation $conversation, User $user, array $data): array
    {
        return DB::transaction(function () use ($conversation, $user, $data) {
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $user = User::where('organization_id', $conversation->organization_id)->findOrFail($user->id);
            Gate::forUser($user)->authorize('reply', $conversation);

            return $this->persist($conversation, $data, 'operator:'.$user->id, null, $user->id);
        }, 3);
    }

    private function persist(Conversation $conversation, array $data, string $senderKey, ?int $visitorId, ?int $operatorId): array
    {
        $hash = hash('sha256', json_encode([$data['kind'], $data['body'], $data['attachment_ids']], JSON_THROW_ON_ERROR));
        $existing = Message::where('conversation_id', $conversation->id)->where('sender_key', $senderKey)->where('client_message_id', $data['client_message_id'])->first();
        $files = Attachment::whereIn('id', $data['attachment_ids'])->orderBy('id')->lockForUpdate()->get();
        if ($files->count() !== count($data['attachment_ids'])) {
            abort(404);
        }
        foreach ($files as $file) {
            if (($visitorId !== null && (int) $file->visitor_session_id !== $visitorId) || ($operatorId !== null && (int) $file->operator_id !== $operatorId)) {
                abort(404);
            }
        }
        if ($existing) {
            if (! hash_equals($existing->payload_hash, $hash)) {
                throw new ChatException('idempotency_conflict', 'Этот ключ уже использован для другого сообщения.');
            }

            return [$existing->load('attachments'), false];
        }
        if ($conversation->status === 'closed') {
            throw new ChatException('conversation_closed', 'Диалог закрыт.');
        }
        foreach ($files as $file) {
            if ($file->message_id !== null) {
                throw new ChatException('attachment_used', 'Файл уже прикреплён к сообщению.');
            }
        }
        $conversation->last_sequence++;
        if ($data['kind'] !== 'note') {
            $conversation->last_public_sequence = $conversation->last_sequence;
        }
        $conversation->version++;
        $conversation->last_message_at = now();
        $conversation->save();
        $message = Message::create([
            'conversation_id' => $conversation->id, 'sequence' => $conversation->last_sequence,
            'sender_key' => $senderKey, 'sender_type' => $visitorId !== null ? 'visitor' : 'operator',
            'operator_id' => $operatorId, 'kind' => $data['kind'], 'body' => $data['body'],
            'client_message_id' => $data['client_message_id'], 'payload_hash' => $hash,
        ]);
        foreach ($files as $file) {
            $file->message_id = $message->id;
            $file->save();
        }
        $event = ChatEvent::create(['event_id' => (string) Str::uuid(), 'event_type' => 'message.created', 'conversation_id' => $conversation->id, 'message_id' => $message->id, 'available_at' => now()]);
        $tenant = (int) $conversation->organization_id;
        DB::afterCommit(function () use ($event, $tenant) {
            try {
                PublishChatEvent::dispatch($tenant, $event->id);
            } catch (\Throwable) {
                Log::warning('chat.outbox.enqueue_failed', ['event_id' => $event->event_id]);
            }
        });

        return [$message->load('attachments'), true];
    }
}

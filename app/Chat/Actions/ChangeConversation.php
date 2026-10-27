<?php

namespace App\Chat\Actions;

use App\Chat\ChatException;
use App\Jobs\PublishChatEvent;
use App\Models\ChatEvent;
use App\Models\Conversation;
use App\Models\ConversationActivity;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChangeConversation
{
    public function handle(Conversation $conversation, User $actor, string $operation, array $data): Conversation
    {
        return DB::transaction(function () use ($conversation, $actor, $operation, $data) {
            Organization::whereKey($conversation->organization_id)->lockForUpdate()->firstOrFail();
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $actor = User::where('organization_id', $conversation->organization_id)->findOrFail($actor->id);
            Gate::forUser($actor)->authorize($operation, $conversation);
            if ((int) $data['version'] !== $conversation->version) {
                throw new ChatException('version_conflict', 'Диалог изменён другим участником. Обновите данные и повторите действие.');
            }
            $before = $conversation->only('status', 'assigned_user_id', 'department_id');
            if ($operation === 'accept') {
                if ($conversation->status !== 'pending' || $conversation->assigned_user_id !== null) {
                    throw new ChatException('already_assigned', 'Диалог уже принят или закрыт.');
                }
                $conversation->assigned_user_id = $actor->id;
                $conversation->status = 'open';
            } elseif ($operation === 'assign') {
                if ($conversation->status === 'closed') {
                    throw new ChatException('conversation_closed', 'Сначала верните закрытый диалог в очередь.');
                }
                $department = isset($data['department_id']) ? Department::find($data['department_id']) : null;
                if (isset($data['department_id']) && ! $department) {
                    throw ValidationException::withMessages(['department_id' => 'Отдел недоступен.']);
                }
                $target = isset($data['assigned_user_id']) ? User::where('organization_id', $conversation->organization_id)->lockForUpdate()->find($data['assigned_user_id']) : null;
                if (isset($data['assigned_user_id']) && (! $target || ! $target->hasPermission('chat.reply') || ($department && ! $target->hasPermission('chat.manage') && (int) $target->department_id !== $department->id))) {
                    throw ValidationException::withMessages(['assigned_user_id' => 'Оператор недоступен или не состоит в выбранном отделе.']);
                }
                $conversation->department_id = $department?->id;
                $conversation->assigned_user_id = $target?->id;
                $conversation->status = $target ? 'open' : 'pending';
            } else {
                $target = $data['status'];
                $allowed = ['pending' => ['closed'], 'open' => ['closed', 'pending'], 'closed' => ['pending']];
                if (! in_array($target, $allowed[$conversation->status] ?? [], true)) {
                    throw new ChatException('invalid_transition', 'Этот переход состояния недоступен.');
                }
                $conversation->status = $target;
                if ($target === 'pending') {
                    $conversation->assigned_user_id = null;
                }
            }
            $after = $conversation->only('status', 'assigned_user_id', 'department_id');
            if ($before === $after) {
                return $conversation;
            }
            $conversation->version++;
            $conversation->save();
            ConversationActivity::create(['conversation_id' => $conversation->id, 'actor_id' => $actor->id, 'type' => $operation, 'changes' => ['before' => $before, 'after' => $after], 'reason' => $data['reason'] ?? null, 'version' => $conversation->version]);
            $event = ChatEvent::create(['event_id' => (string) Str::uuid(), 'event_type' => $operation === 'transition' ? 'conversation.status_changed' : 'conversation.assigned', 'conversation_id' => $conversation->id, 'message_id' => null, 'aggregate_version' => $conversation->version, 'available_at' => now()]);
            DB::afterCommit(function () use ($event, $conversation) {
                try {
                    PublishChatEvent::dispatch((int) $conversation->organization_id, $event->id);
                } catch (\Throwable) {
                    Log::warning('chat.outbox.enqueue_failed', ['event_id' => $event->event_id]);
                }
            });

            return $conversation;
        }, 3);
    }
}

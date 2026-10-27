<?php

namespace App\Routing;

use App\Jobs\PublishChatEvent;
use App\Models\Channel;
use App\Models\ChatEvent;
use App\Models\Conversation;
use App\Models\ConversationActivity;
use App\Models\OperatorState;
use App\Models\Organization;
use App\Models\RoutingRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RouteConversation
{
    public function handle(Conversation $conversation): Conversation
    {
        return DB::transaction(function () use ($conversation) {
            // Serialize automatic selection across rules sharing the same operator pool.
            $organization = Organization::whereKey($conversation->organization_id)->lockForUpdate()->firstOrFail();
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            if ($conversation->status !== 'pending' || $conversation->assigned_user_id !== null || ! Channel::whereKey($conversation->channel_id)->where('is_active', true)->exists()) {
                return $conversation;
            }
            $key = $conversation->department_id ? 'department:'.$conversation->department_id : 'global';
            $rule = RoutingRule::where('channel_id', $conversation->channel_id)->where('scope_key', $key)->where('is_active', true)->lockForUpdate()->first();
            if (! $rule) {
                return $conversation;
            }
            $users = User::where('organization_id', $conversation->organization_id)->where('is_active', true)->whereIn('role', ['owner', 'admin', 'supervisor', 'operator'])->orderBy('id')->lockForUpdate()->get();
            $states = OperatorState::whereIn('user_id', $users->pluck('id'))->lockForUpdate()->get()->keyBy('user_id');
            $loads = Conversation::where('status', 'open')->whereNotNull('assigned_user_id')->selectRaw('assigned_user_id, count(*) as load_count')->groupBy('assigned_user_id')->pluck('load_count', 'assigned_user_id');
            $eligible = $users->filter(function ($user) use ($states, $loads, $organization, $conversation) {
                $state = $states->get($user->id);
                if (! $state || $state->status !== 'available' || ! $state->heartbeat_at || $state->heartbeat_at->lt(now()->subSeconds(90)) || ($loads[$user->id] ?? 0) >= $state->capacity) {
                    return false;
                }
                if ($conversation->department_id && ! $user->hasPermission('chat.manage') && (int) $user->department_id !== (int) $conversation->department_id) {
                    return false;
                }
                if ($state->schedule !== null) {
                    $local = now()->timezone($organization->timezone ?? 'Europe/Moscow');
                    $window = collect($state->schedule)->firstWhere('day', $local->isoWeekday());
                    if (! $window || $local->format('H:i') < $window['start'] || $local->format('H:i') >= $window['end']) {
                        return false;
                    }
                }

                return true;
            });
            $pool = $eligible->filter(fn ($user) => (int) $user->id !== (int) $rule->fallback_user_id && (! $rule->operator_ids || in_array($user->id, $rule->operator_ids, true)));
            $target = $rule->strategy === 'least_load' ? $pool->sortBy(fn ($user) => sprintf('%010d:%020d', $loads[$user->id] ?? 0, $user->id))->first() : ($pool->first(fn ($user) => $user->id > (int) $rule->last_assigned_id) ?? $pool->first());
            $fallback = false;
            if (! $target && $rule->fallback_user_id) {
                $target = $eligible->firstWhere('id', $rule->fallback_user_id);
                $fallback = true;
            }
            if (! $target) {
                return $conversation;
            }
            $before = $conversation->only('status', 'assigned_user_id', 'department_id');
            $conversation->assigned_user_id = $target->id;
            $conversation->status = 'open';
            $conversation->version++;
            $conversation->save();
            if (! $fallback) {
                $rule->last_assigned_id = $target->id;
                $rule->save();
            }
            ConversationActivity::create(['conversation_id' => $conversation->id, 'actor_id' => null, 'type' => 'route', 'version' => $conversation->version, 'changes' => ['before' => $before, 'after' => $conversation->only('status', 'assigned_user_id', 'department_id'), 'rule_id' => $rule->id, 'strategy' => $rule->strategy, 'fallback' => $fallback], 'reason' => $fallback ? 'Резервное назначение' : 'Автоматическое распределение']);
            $event = ChatEvent::create(['event_id' => (string) Str::uuid(), 'event_type' => 'conversation.assigned', 'conversation_id' => $conversation->id, 'message_id' => null, 'aggregate_version' => $conversation->version, 'available_at' => now()]);
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

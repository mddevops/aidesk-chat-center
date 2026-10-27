<?php

namespace App\Http\Controllers\Chat;

use App\Chat\ChatException;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Department;
use App\Models\OperatorState;
use App\Models\Organization;
use App\Models\RoutingRule;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class RoutingController extends Controller
{
    public function page()
    {
        Gate::authorize('chat.manage');

        return Inertia::render('Routing/Index', ['channels' => Channel::orderBy('name')->get(['id', 'name']), 'departments' => Department::orderBy('name')->get(['id', 'name']), 'operators' => User::where('organization_id', app(TenantContext::class)->id())->where('is_active', true)->whereIn('role', ['owner', 'admin', 'supervisor', 'operator'])->orderBy('name')->get(['id', 'name', 'department_id', 'role']), 'timezone' => Organization::findOrFail(app(TenantContext::class)->id())->timezone]);
    }

    public function index()
    {
        Gate::authorize('chat.manage');

        return response()->json(['data' => ['rules' => RoutingRule::orderBy('id')->get(), 'states' => OperatorState::get()]]);
    }

    public function rule(Request $request, Channel $channel)
    {
        Gate::authorize('chat.manage');
        $data = $request->validate(['department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('organization_id', app(TenantContext::class)->id())], 'strategy' => ['required', Rule::in(['round_robin', 'least_load'])], 'operator_ids' => ['present', 'array', 'max:200'], 'operator_ids.*' => ['integer', 'distinct'], 'fallback_user_id' => ['nullable', 'integer', 'min:1'], 'is_active' => ['required', 'boolean'], 'revision' => ['required', 'integer', 'min:0']]);
        $data['operator_ids'] = array_map('intval', $data['operator_ids']);
        $rule = DB::transaction(function () use ($data, $channel) {
            Organization::whereKey(app(TenantContext::class)->id())->lockForUpdate()->firstOrFail();
            $ids = array_unique([...$data['operator_ids'], ...(! empty($data['fallback_user_id']) ? [(int) $data['fallback_user_id']] : [])]);
            $users = User::where('organization_id', app(TenantContext::class)->id())->whereIn('id', $ids)->where('is_active', true)->lockForUpdate()->get();
            if ($users->count() !== count($ids) || $users->contains(fn ($user) => ! $user->hasPermission('chat.reply') || (! empty($data['department_id']) && ! $user->hasPermission('chat.manage') && (int) $user->department_id !== (int) $data['department_id']))) {
                throw ValidationException::withMessages(['operator_ids' => 'Выберите действующих операторов своей организации и отдела.']);
            }
            if (! empty($data['fallback_user_id']) && in_array((int) $data['fallback_user_id'], $data['operator_ids'], true)) {
                throw ValidationException::withMessages(['fallback_user_id' => 'Резервный оператор должен отличаться от основной группы.']);
            }
            $key = ! empty($data['department_id']) ? 'department:'.$data['department_id'] : 'global';
            $rule = RoutingRule::where('channel_id', $channel->id)->where('scope_key', $key)->lockForUpdate()->first();
            if (($rule?->revision ?? 0) !== (int) $data['revision']) {
                throw new ChatException('version_conflict', 'Правило изменилось. Обновите список перед сохранением.', 409);
            }
            $values = [...$data, 'channel_id' => $channel->id, 'scope_key' => $key, 'revision' => ($rule?->revision ?? 0) + 1];
            if ($rule) {
                $rule->update($values);
            } else {
                $rule = RoutingRule::create($values);
            }

            return $rule->fresh();
        }, 3);

        return response()->json(['data' => $rule]);
    }

    public function presence(Request $request)
    {
        Gate::authorize('chat.reply');
        $state = OperatorState::where('user_id', $request->user()->id)->first();

        return response()->json(['data' => $this->presenceData($state)]);
    }

    public function heartbeat(Request $request)
    {
        Gate::authorize('chat.reply');
        $data = $request->validate(['status' => ['required', Rule::in(['available', 'away', 'offline'])], 'revision' => ['required', 'integer', 'min:0']]);
        $state = DB::transaction(function () use ($request, $data) {
            // Match routing lock order, including the first state row FK insert.
            Organization::whereKey(app(TenantContext::class)->id())->lockForUpdate()->firstOrFail();
            User::where('organization_id', app(TenantContext::class)->id())->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $state = OperatorState::lockForUpdate()->firstOrCreate(['user_id' => $request->user()->id]);
            $state->refresh();
            if ($state->revision !== (int) $data['revision']) {
                throw new ChatException('version_conflict', 'Доступность изменена в другой вкладке. Обновите статус.', 409);
            }
            $state->revision += $state->status !== $data['status'] ? 1 : 0;
            $state->status = $data['status'];
            $state->heartbeat_at = now();
            $state->save();

            return $state;
        }, 3);

        return response()->json(['data' => $this->presenceData($state)]);
    }

    private function presenceData(?OperatorState $state): array
    {
        return ['revision' => $state?->revision ?? 0, 'status' => $state && $state->heartbeat_at?->gte(now()->subSeconds(90)) ? $state->status : 'offline', 'heartbeat_at' => $state?->heartbeat_at?->toISOString(), 'capacity' => $state?->capacity ?? 5];
    }

    public function profile(Request $request, string $userId)
    {
        Gate::authorize('chat.manage');
        $data = $request->validate(['capacity' => ['required', 'integer', 'between:1,100'], 'schedule' => ['present', 'nullable', 'array', 'max:7'], 'schedule.*' => ['required', 'array:day,start,end'], 'schedule.*.day' => ['required', 'integer', 'between:1,7', 'distinct'], 'schedule.*.start' => ['required', 'date_format:H:i'], 'schedule.*.end' => ['required', 'date_format:H:i']]);
        foreach ($data['schedule'] ?? [] as $window) {
            if ($window['start'] >= $window['end']) {
                throw ValidationException::withMessages(['schedule' => 'Начало смены должно предшествовать окончанию. Ночные смены пока не поддерживаются.']);
            }
        }
        if ($data['schedule'] !== null) {
            $data['schedule'] = array_map(fn ($window) => [...$window, 'day' => (int) $window['day']], $data['schedule']);
        }
        $state = DB::transaction(function () use ($userId, $data) {
            Organization::whereKey(app(TenantContext::class)->id())->lockForUpdate()->firstOrFail();
            $user = User::where('organization_id', app(TenantContext::class)->id())->whereKey($userId)->where('is_active', true)->lockForUpdate()->firstOrFail();
            abort_unless($user->hasPermission('chat.reply'), 422);

            return OperatorState::updateOrCreate(['user_id' => $user->id], $data);
        }, 3);

        return response()->json(['data' => $state->fresh()]);
    }
}

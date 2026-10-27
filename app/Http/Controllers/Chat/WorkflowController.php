<?php

namespace App\Http\Controllers\Chat;

use App\Chat\Actions\ChangeConversation;
use App\Chat\ConversationPayload;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationActivity;
use App\Models\QuickReply;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class WorkflowController extends Controller
{
    public function change(Request $request, Conversation $conversation, ChangeConversation $action)
    {
        $operation = $request->route()->getName();
        $rules = ['version' => ['required', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:500']];
        if ($operation === 'assign') {
            $rules += ['assigned_user_id' => ['present', 'nullable', 'integer'], 'department_id' => ['present', 'nullable', 'integer']];
        }
        if ($operation === 'transition') {
            $rules['status'] = ['required', Rule::in(['pending', 'open', 'closed'])];
        }
        Gate::authorize($operation, $conversation);
        $changed = $action->handle($conversation, $request->user(), $operation, $request->validate($rules));

        return response()->json(['data' => ConversationPayload::make($changed->load(['contact', 'channel', 'assignedUser', 'department', 'latestMessage']), $request->user())]);
    }

    public function activities(Conversation $conversation)
    {
        Gate::authorize('view', $conversation);
        $rows = ConversationActivity::with('actor:id,name')->where('conversation_id', $conversation->id)->orderByDesc('id')->limit(20)->get();

        return response()->json(['data' => $rows->map(fn ($row) => ['id' => $row->id, 'type' => $row->type, 'changes' => $row->changes, 'reason' => $row->reason, 'version' => $row->version, 'actor' => $row->actor?->only('id', 'name') ?? ['id' => null, 'name' => 'Система'], 'created_at' => $row->created_at->toISOString()])]);
    }

    public function replies()
    {
        Gate::authorize('chat.view');

        return response()->json(['data' => QuickReply::orderBy('title')->get(['id', 'title', 'body'])]);
    }

    public function storeReply(Request $request)
    {
        Gate::authorize('chat.manage');

        return response()->json(['data' => QuickReply::create($this->replyData($request))], 201);
    }

    public function updateReply(Request $request, QuickReply $quickReply)
    {
        Gate::authorize('chat.manage');
        $quickReply->update($this->replyData($request, $quickReply->id));

        return response()->json(['data' => $quickReply]);
    }

    private function replyData(Request $request, ?int $id = null): array
    {
        return $request->validate(['title' => ['required', 'string', 'max:100', Rule::unique('quick_replies')->where('organization_id', app(TenantContext::class)->id())->ignore($id)], 'body' => ['required', 'string', 'max:10000']]);
    }
}

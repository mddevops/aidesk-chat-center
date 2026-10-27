<?php

namespace App\Http\Controllers\Chat;

use App\Chat\Actions\NotifyReceipt;
use App\Chat\Actions\SendMessage;
use App\Chat\Actions\StoreAttachment;
use App\Chat\ChatPayload;
use App\Chat\ConversationPayload;
use App\Chat\MessageData;
use App\Chat\PhoneNumber;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationRead;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Models\VisitorSession;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OperatorController extends Controller
{
    public function channels()
    {
        Gate::authorize('chat.view');

        return response()->json(['data' => Channel::orderBy('id')->get(['id', 'public_id', 'site_id', 'kind', 'name', 'is_active'])]);
    }

    public function storeChannel(Request $request)
    {
        Gate::authorize('directory.manage');
        $id = app(TenantContext::class)->id();
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'site_id' => ['required', 'integer', Rule::exists('sites', 'id')->where('organization_id', $id), Rule::unique('channels')->where('organization_id', $id)->where('kind', 'website')], 'kind' => ['prohibited']]);

        return response()->json(['data' => Channel::create([...$data, 'kind' => 'website'])->only('id', 'public_id', 'site_id', 'kind', 'name', 'is_active')], 201);
    }

    public function index(Request $request)
    {
        Gate::authorize('chat.view');
        $data = $request->validate(['q' => ['sometimes', 'string', 'max:100'], 'view' => ['sometimes', Rule::in(['all', 'mine', 'incoming'])], 'status' => ['sometimes', Rule::in(['pending', 'open', 'closed'])], 'channel_id' => ['sometimes', 'integer'], 'department_id' => ['sometimes', 'integer']]);
        $query = Conversation::visibleTo($request->user())->with(['contact', 'channel', 'assignedUser:id,name', 'department', 'latestMessage']);
        $query->addSelect(['last_read_sequence' => ConversationRead::select('last_read_sequence')->whereColumn('conversation_reads.conversation_id', 'conversations.id')->where('user_id', $request->user()->id)->limit(1)]);
        $query->selectSub(Message::selectRaw('COUNT(*)')->whereColumn('messages.conversation_id', 'conversations.id')->where('sender_type', 'visitor')->where('kind', 'message')->whereRaw('messages.sequence > COALESCE((SELECT last_read_sequence FROM conversation_reads WHERE conversation_reads.conversation_id = conversations.id AND user_id = ?), 0)', [$request->user()->id]), 'unread_count');
        if (($data['view'] ?? 'all') === 'mine') {
            $query->where('assigned_user_id', $request->user()->id);
        }
        if (($data['view'] ?? 'all') === 'incoming') {
            $query->whereNull('assigned_user_id')->where('status', 'pending');
        }
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        foreach (['channel_id', 'department_id'] as $filter) {
            if (isset($data[$filter])) {
                $query->where($filter, $data[$filter]);
            }
        }
        if (! empty($data['q'])) {
            $needle = '%'.addcslashes(trim($data['q']), '%_\\').'%';
            $digits = preg_replace('/\D/', '', $data['q']);
            try {
                $digits = ltrim(PhoneNumber::normalize($data['q']), '+');
            } catch (ValidationException) {
            }
            $query->whereHas('contact', function ($q) use ($needle, $digits) {
                $q->where('name', 'like', $needle);
                if (strlen($digits) >= 3) {
                    $q->orWhere('phone', 'like', '%'.$digits.'%');
                }
            });
        }
        $page = $query->orderByDesc('last_message_at')->orderByDesc('id')->cursorPaginate(30);

        return response()->json(['data' => $page->getCollection()->map(fn ($conversation) => ConversationPayload::make($conversation, $request->user())), 'meta' => ['next_cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]]);
    }

    public function show(Request $request, Conversation $conversation)
    {
        Gate::authorize('view', $conversation);

        return response()->json(['data' => ConversationPayload::make($conversation->load(['contact', 'channel', 'assignedUser', 'department', 'latestMessage']), $request->user())]);
    }

    public function messages(Request $request, Conversation $conversation)
    {
        Gate::authorize('view', $conversation);
        $data = $request->validate(['after_sequence' => ['sometimes', 'integer', 'min:0'], 'before_sequence' => ['sometimes', 'integer', 'min:1'], 'latest' => ['sometimes', 'boolean']]);
        $older = $request->boolean('latest') || isset($data['before_sequence']);
        $query = Message::with(['attachments', 'operator:id,name'])->where('conversation_id', $conversation->id);
        if (isset($data['before_sequence'])) {
            $query->where('sequence', '<', $data['before_sequence']);
        } elseif (! $older) {
            $query->where('sequence', '>', $data['after_sequence'] ?? 0);
        }
        $messages = $query->orderBy('sequence', $older ? 'desc' : 'asc')->limit(101)->get();
        $hasMore = $messages->count() > 100;
        $messages = $messages->take(100);
        if ($older) {
            $messages = $messages->reverse()->values();
        }

        $visitor = VisitorSession::findOrFail($conversation->visitor_session_id);

        return response()->json(['data' => $messages->map(fn ($message) => [...ChatPayload::message($message), 'delivery_status' => $message->sender_type === 'operator' && $message->kind === 'message' ? ($message->sequence <= $visitor->last_read_sequence ? 'read' : ($message->sequence <= $visitor->last_delivered_sequence ? 'delivered' : 'stored')) : 'stored']), 'meta' => ['visitor_last_read_sequence' => $visitor->last_read_sequence, 'visitor_last_delivered_sequence' => $visitor->last_delivered_sequence, 'last_sequence' => $conversation->last_sequence, 'next_after_sequence' => $messages->last()?->sequence ?? (int) ($data['after_sequence'] ?? 0), 'next_before_sequence' => $messages->first()?->sequence, 'has_more' => ! $older && $hasMore, 'has_older' => $older && $hasMore]]);
    }

    public function send(Request $request, Conversation $conversation, SendMessage $action)
    {
        Gate::authorize('reply', $conversation);
        [$message, $created] = $action->operator($conversation, $request->user(), MessageData::fromRequest($request, true));

        return response()->json(['data' => ChatPayload::message($message)], $created ? 201 : 200);
    }

    public function read(Request $request, Conversation $conversation)
    {
        Gate::authorize('reply', $conversation);
        $data = $request->validate(['last_read_sequence' => ['required', 'integer', 'min:0']]);
        $read = DB::transaction(function () use ($request, $conversation, $data) {
            Organization::whereKey($conversation->organization_id)->lockForUpdate()->firstOrFail();
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $reader = User::where('organization_id', $conversation->organization_id)->findOrFail($request->user()->id);
            Gate::forUser($reader)->authorize('reply', $conversation);
            if ($data['last_read_sequence'] > $conversation->last_sequence) {
                throw ValidationException::withMessages(['last_read_sequence' => 'Сообщение ещё не сохранено.']);
            }
            $read = ConversationRead::firstOrNew(['conversation_id' => $conversation->id, 'user_id' => $request->user()->id]);
            $read->last_read_sequence = max($read->last_read_sequence ?? 0, $data['last_read_sequence']);
            $changed = $read->isDirty('last_read_sequence');
            $read->save();
            if ($changed) {
                NotifyReceipt::record($conversation);
            }

            return $read;
        }, 3);

        return response()->json(['data' => ['last_read_sequence' => $read->last_read_sequence]]);
    }

    public function upload(Request $request, StoreAttachment $action)
    {
        Gate::authorize('chat.reply');

        return response()->json(['data' => ChatPayload::attachment($action->handle($request, null, $request->user()->id))], 201);
    }

    public function download(Request $request, string $attachment)
    {
        Gate::authorize('chat.view');
        $file = Attachment::findOrFail($attachment);
        if ($file->message_id) {
            $message = Message::findOrFail($file->message_id);
            Gate::authorize('view', Conversation::findOrFail($message->conversation_id));
        } else {
            abort_unless((int) $file->operator_id === $request->user()->id, 404);
        }

        return Storage::disk('local')->download($file->path, $file->name, ['Content-Type' => $file->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}

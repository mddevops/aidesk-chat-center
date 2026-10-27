<?php

namespace App\Http\Controllers\Chat;

use App\Chat\Actions\NotifyReceipt;
use App\Chat\Actions\SendMessage;
use App\Chat\Actions\StoreAttachment;
use App\Chat\ChatException;
use App\Chat\ChatPayload;
use App\Chat\MessageData;
use App\Chat\PhoneNumber;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationRead;
use App\Models\Department;
use App\Models\Message;
use App\Models\Organization;
use App\Models\VisitorSession;
use App\Models\Widget;
use App\Models\WidgetVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VisitorController extends Controller
{
    public function realtime(Request $request)
    {
        $session = $request->attributes->get('visitor_session');

        return response()->json(['data' => ['channel' => 'organizations.'.$session->organization_id.'.visitors.'.$session->public_id, 'expires_at' => $session->expires_at->toISOString()]]);
    }

    public function session(Request $request)
    {
        $token = bin2hex(random_bytes(32));
        $session = VisitorSession::create(['channel_id' => $request->attributes->get('widget_channel')->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(config('chat.visitor_session_days'))]);

        return response()->json(['data' => ['credential' => $token, 'session_id' => $session->public_id, 'expires_at' => $session->expires_at->toISOString()]], 201);
    }

    public function identify(Request $request)
    {
        // Normalize before validation so malformed phones are reported together with consent errors.
        $phoneError = null;
        try {
            $phone = PhoneNumber::normalize(is_string($request->input('phone')) ? $request->input('phone') : '');
        } catch (ValidationException $error) {
            $phoneError = $error->errors()['phone'][0];
            $phone = '';
        }
        $validator = validator($request->all(), ['name' => ['required', 'string', 'max:150'], 'phone' => ['required', 'string', 'max:50'], 'consent' => ['required', 'accepted'], 'consent_version' => ['required', Rule::in([config('chat.consent_version')])]]);
        if ($phoneError) {
            $validator->after(fn ($validator) => $validator->errors()->add('phone', $phoneError));
        }
        $validator->addRules(['department_id' => ['nullable', 'integer']]);
        $data = $validator->validate();
        $widget = Widget::where('channel_id', $request->attributes->get('widget_channel')->id)->first();
        if ($widget && $widget->published_version) {
            $configuration = WidgetVersion::where('widget_id', $widget->id)->where('version', $widget->published_version)->firstOrFail()->configuration;
            $departments = $configuration['department_ids'] ?? [];
            if (($departments || ! empty($data['department_id'])) && ! in_array((int) ($data['department_id'] ?? 0), $departments, true)) {
                throw ValidationException::withMessages(['department_id' => 'Выберите доступный отдел.']);
            }
        } elseif (! empty($data['department_id'])) {
            throw ValidationException::withMessages(['department_id' => 'Выбор отдела недоступен.']);
        }
        if (! empty($data['department_id']) && ! Department::whereKey($data['department_id'])->exists()) {
            throw ValidationException::withMessages(['department_id' => 'Отдел недоступен.']);
        }
        $session = DB::transaction(function () use ($request, $data, $phone) {
            $session = VisitorSession::whereKey($request->attributes->get('visitor_session')->id)->lockForUpdate()->firstOrFail();
            if ($session->revoked_at || $session->expires_at->isPast()) {
                throw new ChatException('invalid_credential', 'Сессия посетителя недействительна.', 401);
            }
            if ($session->contact_id) {
                $contact = Contact::findOrFail($session->contact_id);
                if ($contact->name !== trim($data['name']) || $contact->phone !== $phone || $session->consent_version !== $data['consent_version'] || (int) $session->department_id !== (int) ($data['department_id'] ?? 0)) {
                    throw new ChatException('identity_locked', 'Контакт этой сессии уже указан.');
                }
            } else {
                $contact = Contact::create(['name' => trim($data['name']), 'phone' => $phone]);
                $session->department_id = $data['department_id'] ?? null;
                $session->contact_id = $contact->id;
                $session->consent_version = $data['consent_version'];
                $session->consented_at = now();
                $session->save();
            }

            return $session;
        }, 3);

        return response()->json(['data' => ['identified' => true, 'session_id' => $session->public_id]]);
    }

    private function conversation(Request $request): ?Conversation
    {
        return Conversation::where('visitor_session_id', $request->attributes->get('visitor_session')->id)->first();
    }

    public function current(Request $request)
    {
        return response()->json(['data' => ChatPayload::visitorConversation($this->conversation($request)), 'identified' => (bool) $request->attributes->get('visitor_session')->contact_id]);
    }

    public function messages(Request $request)
    {
        $data = $request->validate(['after_sequence' => ['sometimes', 'integer', 'min:0']]);
        $conversation = $this->conversation($request);
        $messages = $conversation ? Message::with(['attachments', 'operator:id,name'])->where('conversation_id', $conversation->id)->where('kind', 'message')->where('sequence', '>', $data['after_sequence'] ?? 0)->orderBy('sequence')->limit(101)->get() : collect();

        $readHead = $conversation ? (int) ConversationRead::where('conversation_id', $conversation->id)->max('last_read_sequence') : 0;
        $readHead = $conversation ? (int) Message::where('conversation_id', $conversation->id)->where('kind', 'message')->where('sequence', '<=', $readHead)->max('sequence') : 0;
        $hasMore = $messages->count() > 100;
        $messages = $messages->take(100);

        return response()->json(['data' => $messages->map(fn ($message) => [...ChatPayload::message($message), 'delivery_status' => $message->sender_type === 'visitor' && $message->sequence <= $readHead ? 'read' : 'stored']), 'meta' => ['operator_read_sequence' => $readHead, 'last_sequence' => $conversation?->last_public_sequence ?? 0, 'next_after_sequence' => $messages->last()?->sequence ?? (int) ($data['after_sequence'] ?? 0), 'has_more' => $hasMore]]);
    }

    public function send(Request $request, SendMessage $action)
    {
        [$message, $created] = $action->visitor($request->attributes->get('visitor_session'), MessageData::fromRequest($request));

        return response()->json(['data' => ChatPayload::message($message)], $created ? 201 : 200);
    }

    public function read(Request $request)
    {
        return $this->acknowledge($request, 'last_read_sequence');
    }

    public function delivered(Request $request)
    {
        return $this->acknowledge($request, 'last_delivered_sequence');
    }

    private function acknowledge(Request $request, string $key)
    {
        $data = $request->validate([$key => ['required', 'integer', 'min:0']]);
        $session = DB::transaction(function () use ($request, $data, $key) {
            $session = VisitorSession::whereKey($request->attributes->get('visitor_session')->id)->lockForUpdate()->firstOrFail();
            if ($session->revoked_at || $session->expires_at->isPast()) {
                throw new ChatException('invalid_credential', 'Сессия посетителя недействительна.', 401);
            }
            Organization::whereKey($session->organization_id)->lockForUpdate()->firstOrFail();
            $conversation = Conversation::where('visitor_session_id', $session->id)->lockForUpdate()->first();
            if ($data[$key] > ($conversation?->last_public_sequence ?? 0)) {
                throw ValidationException::withMessages([$key => 'Сообщение ещё не сохранено.']);
            }
            $session->$key = max($session->$key, $data[$key]);
            if ($key === 'last_read_sequence') {
                $session->last_delivered_sequence = max($session->last_delivered_sequence, $session->last_read_sequence);
            }
            $changed = $session->isDirty(['last_read_sequence', 'last_delivered_sequence']);
            $session->save();
            if ($changed && $conversation) {
                NotifyReceipt::record($conversation);
            }

            return $session;
        }, 3);

        return response()->json(['data' => [$key => $session->$key]]);
    }

    public function revoke(Request $request)
    {
        $session = $request->attributes->get('visitor_session');
        $session->revoked_at = now();
        $session->save();

        return response()->json(['data' => ['revoked' => true]]);
    }

    public function upload(Request $request, StoreAttachment $action)
    {
        if (! $request->attributes->get('visitor_session')->contact_id) {
            throw new ChatException('identity_required', 'Сначала укажите контактные данные.', 403);
        }

        return response()->json(['data' => ChatPayload::attachment($action->handle($request, $request->attributes->get('visitor_session')->id, null))], 201);
    }

    public function download(Request $request, string $channelPublicId, string $attachment)
    {
        $file = Attachment::findOrFail($attachment);
        $session = $request->attributes->get('visitor_session');
        $message = $file->message_id ? Message::findOrFail($file->message_id) : null;
        if ($message) {
            $conversation = $this->conversation($request);
            abort_unless($conversation && $message->kind === 'message' && (int) $message->conversation_id === $conversation->id, 404);
        } else {
            abort_unless((int) $file->visitor_session_id === $session->id, 404);
        }

        return Storage::disk('local')->download($file->path, $file->name, ['Content-Type' => $file->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}

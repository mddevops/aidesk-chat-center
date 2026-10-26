<?php

use App\Chat\PhoneNumber;
use App\Enums\Role;
use App\Events\ChatMessageAvailable;
use App\Jobs\PublishChatEvent;
use App\Models\Channel;
use App\Models\ChatEvent;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationRead;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Showroom;
use App\Models\Site;
use App\Models\User;
use App\Models\VisitorSession;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function chatTenant(): array
{
    $org = Organization::create(['name' => 'Чат '.Str::uuid()]);
    $user = User::factory()->create(['organization_id' => $org->id, 'role' => Role::Owner]);
    $context = app(TenantContext::class);
    $context->set($org->id);
    try {
        $salon = Showroom::create(['name' => 'Автосалон']);
        $site = Site::create(['name' => 'Сайт', 'origin' => 'https://dealer.example', 'showroom_id' => $salon->id]);
        $channel = Channel::create(['name' => 'Чат сайта', 'site_id' => $site->id, 'kind' => 'website']);
    } finally {
        $context->clear();
    }

    return [$org, $user, $channel];
}

function chatBrowser($test, Channel $channel, bool $identify = true): array
{
    $base = '/widget/v1/channels/'.$channel->public_id;
    $test->withHeaders(['Origin' => 'https://dealer.example', 'Accept' => 'application/json']);
    $result = $test->postJson($base.'/sessions')->assertCreated();
    $token = $result->json('data.credential');
    $test->withHeader('Authorization', 'Bearer '.$token);
    if ($identify) {
        $test->postJson($base.'/identify', ['name' => 'Иван', 'phone' => '8 (999) 123-45-67', 'consent' => true, 'consent_version' => 'contact-v1'])->assertOk();
    }

    return [$base, $token, $result->json('data.session_id')];
}

function chatSend($test, string $base, string $body = 'Здравствуйте', ?string $key = null)
{
    return $test->postJson($base.'/messages', ['body' => $body, 'client_message_id' => $key ?? (string) Str::uuid()]);
}

it('requires name phone and consent on the server before accepting a message', function () {
    Queue::fake();
    [, , $channel] = chatTenant();
    [$base] = chatBrowser($this, $channel, false);
    chatSend($this, $base)->assertStatus(403)->assertJsonPath('error.code', 'identity_required');
    $this->postJson($base.'/identify', ['name' => 'Иван', 'phone' => '123', 'consent' => false, 'consent_version' => 'contact-v1'])->assertUnprocessable()->assertJsonValidationErrors(['phone', 'consent']);
    expect(Conversation::withoutGlobalScopes()->count())->toBe(0);
});

it('normalizes phone numbers but never restores history through a phone match', function () {
    Queue::fake();
    [, , $channel] = chatTenant();
    [$base, $firstToken] = chatBrowser($this, $channel);
    chatSend($this, $base, 'Первое обращение')->assertCreated();
    $this->getJson($base.'/messages')->assertOk()->assertJsonCount(1, 'data');
    [, $secondToken] = chatBrowser($this, $channel);
    expect($secondToken)->not->toBe($firstToken);
    $this->getJson($base.'/messages')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($base.'/conversations/current')->assertOk()->assertJsonPath('data', null);
    expect(Contact::withoutGlobalScopes()->pluck('phone')->all())->toBe(['+79991234567', '+79991234567']);
    $this->withHeader('Authorization', 'Bearer '.$firstToken)->getJson($base.'/messages')->assertJsonPath('data.0.body', 'Первое обращение');
    expect(VisitorSession::withoutGlobalScopes()->first()->token_hash)->not->toBe($firstToken);
});

it('deduplicates committed messages and conflicts on changed payloads', function () {
    Queue::fake();
    [, , $channel] = chatTenant();
    [$base] = chatBrowser($this, $channel);
    $key = (string) Str::uuid();
    $first = chatSend($this, $base, 'Тест', $key)->assertCreated()->json('data.id');
    chatSend($this, $base, 'Тест', $key)->assertOk()->assertJsonPath('data.id', $first);
    chatSend($this, $base, 'Другой текст', $key)->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');
    expect(Message::withoutGlobalScopes()->count())->toBe(1);
    expect(Conversation::withoutGlobalScopes()->count())->toBe(1);
    $this->assertDatabaseCount('chat_events', 1);
});

it('rejects missing expired revoked and cross-channel visitor credentials', function () {
    Queue::fake();
    [, , $channel] = chatTenant();
    [$base, $token] = chatBrowser($this, $channel);
    [, , $other] = chatTenant();
    $this->getJson('/widget/v1/channels/'.$other->public_id.'/messages')->assertUnauthorized();
    VisitorSession::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->update(['expires_at' => now()->subSecond()]);
    $this->getJson($base.'/messages')->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer wrong')->getJson($base.'/messages')->assertUnauthorized();
    expect(app(TenantContext::class)->id())->toBeNull();
});

it('keeps notes private and checks tenant policies before returning message history', function () {
    Queue::fake();
    [$org, $owner, $channel] = chatTenant();
    [$base, $token] = chatBrowser($this, $channel);
    chatSend($this, $base)->assertCreated();
    $conversation = Conversation::withoutGlobalScopes()->firstOrFail();
    $this->actingAs($owner)->postJson('/api/v1/conversations/'.$conversation->id.'/messages', ['body' => 'Только для команды', 'kind' => 'note', 'client_message_id' => (string) Str::uuid()])->assertCreated();
    $this->postJson('/api/v1/conversations/'.$conversation->id.'/messages', ['body' => 'Добрый день', 'kind' => 'message', 'client_message_id' => (string) Str::uuid()])->assertCreated();
    $this->withHeader('Authorization', 'Bearer '.$token)->getJson($base.'/messages')->assertJsonCount(2, 'data')->assertJsonMissing(['body' => 'Только для команды'])->assertJsonPath('data.1.sequence', 3);
    [, $foreign] = chatTenant();
    $this->actingAs($foreign)->getJson('/api/v1/conversations/'.$conversation->id.'/messages')->assertNotFound();
    $analyst = User::factory()->create(['organization_id' => $org->id, 'role' => Role::Analyst]);
    $this->actingAs($analyst)->getJson('/api/v1/conversations/'.$conversation->id.'/messages')->assertForbidden();
});

it('does not mutate read markers on GET and recovers history after a sequence', function () {
    Queue::fake();
    [, , $channel] = chatTenant();
    [$base] = chatBrowser($this, $channel);
    chatSend($this, $base, 'Первое')->assertCreated();
    chatSend($this, $base, 'Второе')->assertCreated();
    $this->getJson($base.'/messages?after_sequence=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'Второе');
    expect(VisitorSession::withoutGlobalScopes()->first()->last_read_sequence)->toBe(0);
    $this->postJson($base.'/read', ['last_read_sequence' => 2])->assertOk();
    $this->postJson($base.'/read', ['last_read_sequence' => 1])->assertOk()->assertJsonPath('data.last_read_sequence', 2);
    $this->postJson($base.'/read', ['last_read_sequence' => 999])->assertUnprocessable();
});

it('stores private attachments and refuses other visitors files and executable content', function () {
    Queue::fake();
    Storage::fake('local');
    [, , $channel] = chatTenant();
    [$base, $firstToken] = chatBrowser($this, $channel);
    $file = UploadedFile::fake()->createWithContent('document.pdf', "%PDF-1.7\nAIDesk test\n%%EOF");
    $upload = $this->post($base.'/attachments', ['file' => $file])->assertCreated();
    $id = $upload->json('data.id');
    $this->post($base.'/attachments', ['file' => UploadedFile::fake()->createWithContent('attack.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])->assertUnprocessable();
    chatBrowser($this, $channel);
    $this->postJson($base.'/messages', ['body' => '', 'attachment_ids' => [$id], 'client_message_id' => (string) Str::uuid()])->assertNotFound();
    $this->getJson($base.'/attachments/'.$id)->assertNotFound();
    $this->withHeader('Authorization', 'Bearer '.$firstToken)->postJson($base.'/messages', ['body' => '', 'attachment_ids' => [$id], 'client_message_id' => (string) Str::uuid()])->assertCreated();
    $this->get($base.'/attachments/'.$id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('enforces origin restrictions and fails closed for scoped chat models', function () {
    [, , $channel] = chatTenant();
    $base = '/widget/v1/channels/'.$channel->public_id;
    $this->withHeader('Origin', 'https://evil.example')->postJson($base.'/sessions')->assertForbidden();
    $this->withHeader('Origin', 'https://dealer.example')->options($base.'/messages', [], ['Access-Control-Request-Method' => 'POST'])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://dealer.example');
    expect(Contact::count())->toBe(0);
    expect(VisitorSession::count())->toBe(0);
    expect(Conversation::count())->toBe(0);
    expect(Message::count())->toBe(0);
});

it('keeps the outbox pending on publish failure and safely retries with minimal payloads', function () {
    Queue::fake();
    [$org, , $channel] = chatTenant();
    [$base] = chatBrowser($this, $channel);
    chatSend($this, $base, 'Не передавать текст в событии')->assertCreated();
    $event = ChatEvent::withoutGlobalScopes()->firstOrFail();
    Broadcast::shouldReceive('queue')->andThrow(new RuntimeException('Simulated Reverb outage'));
    expect(fn () => (new PublishChatEvent($org->id, $event->id))->handle())->toThrow(RuntimeException::class);
    expect($event->fresh()->published_at)->toBeNull();
    expect($event->fresh()->attempts)->toBe(1);
    $this->travel(6)->seconds();
    Event::fake([ChatMessageAvailable::class]);
    (new PublishChatEvent($org->id, $event->id))->handle();
    expect($event->fresh()->published_at)->not->toBeNull();
    (new PublishChatEvent($org->id, $event->id))->handle();
    Event::assertDispatchedTimes(ChatMessageAvailable::class, 1);
    Event::assertDispatched(ChatMessageAvailable::class, fn ($event) => ! str_contains(json_encode($event->broadcastWith()), 'Не передавать') && isset($event->broadcastWith()['event_id']));
    expect(app(TenantContext::class)->id())->toBeNull();
});

it('preserves international phone prefixes and refuses extensions and invalid country codes', function () {
    expect(PhoneNumber::normalize('+81 90 1234 567'))->toBe('+81901234567');
    expect(PhoneNumber::normalize('9991234567'))->toBe('+79991234567');
    expect(fn () => PhoneNumber::normalize('+0123456789'))->toThrow(ValidationException::class);
    expect(fn () => PhoneNumber::normalize('89991234567 доб. 12'))->toThrow(ValidationException::class);
});

it('revokes sessions, locks identity and authorizes before returning an idempotent operator result', function () {
    Queue::fake();
    [$org, $owner, $channel] = chatTenant();
    [$base, $token] = chatBrowser($this, $channel);
    $this->postJson($base.'/identify', ['name' => 'Другой', 'phone' => '+79991234567', 'consent' => true, 'consent_version' => 'contact-v1'])->assertStatus(409);
    chatSend($this, $base)->assertCreated();
    $conversation = Conversation::withoutGlobalScopes()->firstOrFail();
    $payload = ['body' => 'Ответ', 'client_message_id' => (string) Str::uuid()];
    $this->actingAs($owner)->postJson('/api/v1/conversations/'.$conversation->id.'/messages', $payload)->assertCreated();
    $owner->is_active = false;
    $owner->save();
    $this->postJson('/api/v1/conversations/'.$conversation->id.'/messages', $payload)->assertForbidden();
    $this->withHeader('Authorization', 'Bearer '.$token)->postJson($base.'/revoke')->assertOk();
    $this->getJson($base.'/messages')->assertUnauthorized()->assertJsonPath('error.code', 'invalid_credential')->assertHeader('X-Request-Id');
});

it('keeps note attachments out of visitor downloads and scopes channel provisioning', function () {
    Queue::fake();
    Storage::fake('local');
    [, $owner, $channel] = chatTenant();
    [$base, $token] = chatBrowser($this, $channel);
    chatSend($this, $base)->assertCreated();
    $conversation = Conversation::withoutGlobalScopes()->firstOrFail();
    $this->actingAs($owner);
    $upload = $this->post('/api/v1/attachments', ['file' => UploadedFile::fake()->createWithContent('team.pdf', "%PDF-1.7\nPrivate note\n%%EOF")])->assertCreated();
    $fileId = $upload->json('data.id');
    $this->postJson('/api/v1/conversations/'.$conversation->id.'/messages', ['body' => 'Приватный файл', 'kind' => 'note', 'attachment_ids' => [$fileId], 'client_message_id' => (string) Str::uuid()])->assertCreated();
    $this->withHeader('Authorization', 'Bearer '.$token)->getJson($base.'/attachments/'.$fileId)->assertNotFound();
    [, , $foreignChannel] = chatTenant();
    $this->postJson('/api/v1/channels', ['name' => 'Чужой сайт', 'site_id' => $foreignChannel->site_id])->assertUnprocessable();
    $this->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/conversations/'.$conversation->id)->assertOk();
    $this->postJson('/api/v1/conversations/'.$conversation->id.'/read', ['last_read_sequence' => 2])->assertOk();
    $this->getJson('/api/v1/conversations/'.$conversation->id.'/messages')->assertJsonCount(2, 'data');
    expect(ConversationRead::withoutGlobalScopes()->first()->last_read_sequence)->toBe(2);
});

it('recovers a history longer than one batch without skipping sequences', function () {
    Queue::fake();
    [$org, , $channel] = chatTenant();
    [$base] = chatBrowser($this, $channel);
    chatSend($this, $base)->assertCreated();
    $conversation = Conversation::withoutGlobalScopes()->firstOrFail();
    $rows = [];
    for ($sequence = 2; $sequence <= 105; $sequence++) {
        $rows[] = ['organization_id' => $org->id, 'conversation_id' => $conversation->id, 'sequence' => $sequence, 'sender_key' => 'visitor:probe', 'sender_type' => 'visitor', 'kind' => 'message', 'body' => 'History '.$sequence, 'client_message_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()];
    }
    DB::table('messages')->insert($rows);
    Conversation::withoutGlobalScopes()->whereKey($conversation->id)->update(['last_sequence' => 105, 'last_public_sequence' => 105]);
    $this->getJson($base.'/messages')->assertJsonCount(100, 'data')->assertJsonPath('meta.has_more', true)->assertJsonPath('meta.next_after_sequence', 100);
    $this->getJson($base.'/messages?after_sequence=100')->assertJsonCount(5, 'data')->assertJsonPath('data.0.sequence', 101)->assertJsonPath('meta.has_more', false)->assertJsonPath('meta.next_after_sequence', 105);
});

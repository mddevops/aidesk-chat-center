<?php

use App\Events\ChatMessageAvailable;
use App\Events\VisitorChatAvailable;
use App\Jobs\PublishChatEvent;
use App\Models\ChatEvent;
use App\Models\Conversation;
use App\Models\VisitorSession;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('signs only the bearer session private channel and rejects foreign expired or revoked credentials', function () {
    [, , $channel] = chatTenant();
    [$base, $token, $sessionId] = chatBrowser($this, $channel, false);
    $metadata = $this->getJson($base.'/realtime')->assertOk()->json('data');
    $name = 'private-'.$metadata['channel'];
    $result = $this->postJson($base.'/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $name])->assertOk()->json();
    $expected = config('broadcasting.connections.reverb.key').':'.hash_hmac('sha256', '123.456:'.$name, config('broadcasting.connections.reverb.secret'));
    expect($result['auth'])->toBe($expected)->and($metadata['channel'])->toContain($sessionId);
    foreach (['private-organizations.999.visitors.'.$sessionId, 'private-organizations.1.operators.1', str_replace($sessionId, (string) Str::uuid(), $name), 'presence-'.$metadata['channel']] as $foreign) {
        $this->postJson($base.'/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $foreign])->assertForbidden();
    }
    $this->postJson($base.'/broadcasting/auth', ['socket_id' => 'bad:socket', 'channel_name' => $name])->assertUnprocessable();
    $this->withHeader('Origin', 'https://evil.example')->postJson($base.'/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $name])->assertForbidden();
    $this->withHeader('Origin', 'https://dealer.example')->postJson($base.'/revoke')->assertOk();
    $this->postJson($base.'/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $name])->assertUnauthorized();
    [$base] = chatBrowser($this, $channel, false);
    $this->travel(31)->days();
    $this->getJson($base.'/realtime')->assertUnauthorized();
});

it('publishes only minimal public hints and suppresses notes and revoked visitor audiences', function () {
    Queue::fake();
    Event::fake([ChatMessageAvailable::class, VisitorChatAvailable::class]);
    [$org, $owner, $channel] = chatTenant();
    [$base] = chatBrowser($this, $channel);
    chatSend($this, $base, 'Секретный текст +79991234567')->assertCreated();
    $conversation = Conversation::withoutGlobalScopes()->firstOrFail();
    $event = ChatEvent::withoutGlobalScopes()->firstOrFail();
    (new PublishChatEvent($org->id, $event->id))->handle();
    Event::assertDispatchedTimes(VisitorChatAvailable::class, 1);
    Event::assertDispatched(VisitorChatAvailable::class, fn ($event) => ! str_contains(json_encode($event->broadcastWith()), '7999') && ! str_contains(json_encode($event->broadcastWith()), 'body') && $event->broadcastOn()[0]->name === 'private-organizations.'.$org->id.'.visitors.'.VisitorSession::withoutGlobalScopes()->first()->public_id);
    $this->actingAs($owner)->postJson('/api/v1/conversations/'.$conversation->id.'/messages', ['kind' => 'note', 'body' => 'Внутренняя заметка', 'client_message_id' => (string) Str::uuid()])->assertCreated();
    $note = ChatEvent::withoutGlobalScopes()->latest('id')->firstOrFail();
    (new PublishChatEvent($org->id, $note->id))->handle();
    Event::assertDispatchedTimes(VisitorChatAvailable::class, 1);
    $this->postJson('/api/v1/conversations/'.$conversation->id.'/messages', ['kind' => 'message', 'body' => 'Ответ', 'client_message_id' => (string) Str::uuid()])->assertCreated();
    $reply = ChatEvent::withoutGlobalScopes()->latest('id')->firstOrFail();
    $this->postJson($base.'/revoke')->assertOk();
    (new PublishChatEvent($org->id, $reply->id))->handle();
    Event::assertDispatchedTimes(VisitorChatAvailable::class, 1);
    expect(app(TenantContext::class)->id())->toBeNull();
});

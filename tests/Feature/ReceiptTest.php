<?php

use App\Models\ChatEvent;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('advances browser acknowledgements monotonically without claiming delivery from a GET or broadcast', function () {
    Queue::fake();
    [, $owner, $channel] = chatTenant();
    [$base] = chatBrowser($this, $channel);
    chatSend($this, $base)->assertCreated();
    $conversation = Conversation::withoutGlobalScopes()->firstOrFail();
    $url = '/api/v1/conversations/'.$conversation->id;
    $this->actingAs($owner)->postJson($url.'/messages', ['body' => 'Ответ', 'client_message_id' => (string) Str::uuid()])->assertCreated();
    $this->getJson($base.'/messages')->assertJsonCount(2, 'data');
    $this->getJson($url.'/messages')->assertJsonPath('data.1.delivery_status', 'stored');
    $this->postJson($base.'/delivered', ['last_delivered_sequence' => 3])->assertUnprocessable();
    $this->postJson($base.'/delivered', ['last_delivered_sequence' => 2])->assertOk();
    $this->getJson($url.'/messages')->assertJsonPath('data.1.delivery_status', 'delivered')->assertJsonPath('meta.visitor_last_read_sequence', 0);
    $this->postJson($base.'/read', ['last_read_sequence' => 2])->assertOk();
    $this->getJson($url.'/messages')->assertJsonPath('data.1.delivery_status', 'read');
    $events = ChatEvent::withoutGlobalScopes()->count();
    $version = $conversation->fresh()->version;
    $this->postJson($base.'/read', ['last_read_sequence' => 1])->assertOk()->assertJsonPath('data.last_read_sequence', 2);
    $this->postJson($base.'/delivered', ['last_delivered_sequence' => 1])->assertOk()->assertJsonPath('data.last_delivered_sequence', 2);
    expect(ChatEvent::withoutGlobalScopes()->count())->toBe($events)->and($conversation->fresh()->version)->toBe($version);
    $this->postJson($url.'/read', ['last_read_sequence' => 2])->assertOk();
    $this->getJson($base.'/messages')->assertJsonPath('data.0.delivery_status', 'read')->assertJsonPath('meta.operator_read_sequence', 2);
    $this->postJson($url.'/messages', ['kind' => 'note', 'body' => 'Заметка', 'client_message_id' => (string) Str::uuid()])->assertCreated();
    $this->postJson($url.'/read', ['last_read_sequence' => 3])->assertOk();
    $this->getJson($base.'/messages')->assertJsonCount(2, 'data')->assertJsonPath('meta.operator_read_sequence', 2);
    $this->postJson($base.'/read', ['last_read_sequence' => 3])->assertUnprocessable();
    $this->postJson($base.'/revoke')->assertOk();
    $this->postJson($base.'/delivered', ['last_delivered_sequence' => 2])->assertUnauthorized();
});

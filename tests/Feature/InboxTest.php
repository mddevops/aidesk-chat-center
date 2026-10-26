<?php

use App\Chat\Actions\SendMessage;
use App\Enums\Role;
use App\Events\ChatMessageAvailable;
use App\Jobs\PublishChatEvent;
use App\Models\Channel;
use App\Models\ChatEvent;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationActivity;
use App\Models\Department;
use App\Models\Organization;
use App\Models\QuickReply;
use App\Models\Showroom;
use App\Models\Site;
use App\Models\User;
use App\Models\VisitorSession;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function inboxFixture(Role $role = Role::Owner): array
{
    Queue::fake();
    $org = Organization::create(['name' => 'Inbox '.Str::uuid()]);
    $user = User::factory()->create(['organization_id' => $org->id, 'role' => $role]);
    app(TenantContext::class)->set($org->id);
    try {
        $salon = Showroom::create(['name' => 'Салон']);
        $site = Site::create(['name' => 'Сайт', 'origin' => 'https://inbox.example', 'showroom_id' => $salon->id]);
        $channel = Channel::create(['name' => 'Чат', 'site_id' => $site->id, 'kind' => 'website']);
        $contact = Contact::create(['name' => 'Иван', 'phone' => '+79991234567']);
        $session = VisitorSession::create(['channel_id' => $channel->id, 'contact_id' => $contact->id, 'token_hash' => hash('sha256', random_bytes(32)), 'expires_at' => now()->addHour()]);
        [$message] = app(SendMessage::class)->visitor($session, ['body' => 'Автомобиль в наличии?', 'kind' => 'message', 'attachment_ids' => [], 'client_message_id' => (string) Str::uuid()]);
        $conversation = Conversation::findOrFail($message->conversation_id);
    } finally {
        app(TenantContext::class)->clear();
    }

    return [$org, $user, $conversation];
}

it('lists unread messages without marking them read', function () {
    [, $owner, $conversation] = inboxFixture();
    $this->actingAs($owner);
    $this->getJson('/api/v1/conversations?q=89991234567')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $conversation->id)->assertJsonPath('data.0.unread_count', 1);
    $this->getJson('/api/v1/conversations/'.$conversation->id.'/messages')->assertOk();
    $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.unread_count', 1);
    $this->postJson('/api/v1/conversations/'.$conversation->id.'/read', ['last_read_sequence' => 1])->assertOk();
    $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.unread_count', 0);
});

it('accepts a pending conversation once and rejects an outdated version', function () {
    [, $operator, $conversation] = inboxFixture(Role::Operator);
    $url = '/api/v1/conversations/'.$conversation->id;
    $this->actingAs($operator)->postJson($url.'/accept', ['version' => $conversation->version])->assertOk()->assertJsonPath('data.assigned_user_id', $operator->id)->assertJsonPath('data.status', 'open');
    $this->postJson($url.'/accept', ['version' => $conversation->version])->assertStatus(409)->assertJsonPath('error.code', 'version_conflict');
    expect(ConversationActivity::withoutGlobalScopes()->count())->toBe(1);
    $this->getJson($url.'/activities')->assertJsonCount(1, 'data');
});

it('enforces department visibility and keeps observers read-only', function () {
    [$org, $operator, $conversation] = inboxFixture(Role::Operator);
    $other = User::factory()->create(['organization_id' => $org->id, 'role' => Role::Operator]);
    Conversation::withoutGlobalScopes()->whereKey($conversation->id)->update(['status' => 'open', 'assigned_user_id' => $other->id]);
    $this->actingAs($operator)->getJson('/api/v1/conversations/'.$conversation->id)->assertOk()->assertJsonPath('data.permissions.reply', false);
    $this->postJson('/api/v1/conversations/'.$conversation->id.'/read', ['last_read_sequence' => 1])->assertForbidden();
    $this->postJson('/api/v1/conversations/'.$conversation->id.'/messages', ['body' => 'Запрещено', 'client_message_id' => (string) Str::uuid()])->assertForbidden();
    app(TenantContext::class)->set($org->id);
    $department = Department::create(['name' => 'Чужой отдел']);
    app(TenantContext::class)->clear();
    Conversation::withoutGlobalScopes()->whereKey($conversation->id)->update(['department_id' => $department->id]);
    $this->getJson('/api/v1/conversations')->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/conversations/'.$conversation->id.'/messages')->assertForbidden();
    $this->postJson('/api/v1/conversations/'.$conversation->id.'/accept', ['version' => 2])->assertForbidden();
});

it('transfers within the tenant and rejects foreign disabled or mismatched operators', function () {
    [$org, $owner, $conversation] = inboxFixture();
    app(TenantContext::class)->set($org->id);
    $department = Department::create(['name' => 'Продажи']);
    app(TenantContext::class)->clear();
    $operator = User::factory()->create(['organization_id' => $org->id, 'role' => Role::Operator, 'department_id' => $department->id]);
    $foreignOrg = Organization::create(['name' => 'Другая']);
    $foreign = User::factory()->create(['organization_id' => $foreignOrg->id, 'role' => Role::Operator]);
    app(TenantContext::class)->set($foreignOrg->id);
    $foreignDepartment = Department::create(['name' => 'Чужой отдел']);
    app(TenantContext::class)->clear();
    $mismatched = User::factory()->create(['organization_id' => $org->id, 'role' => Role::Operator]);
    $url = '/api/v1/conversations/'.$conversation->id.'/assign';
    $this->actingAs($owner)->postJson($url, ['version' => $conversation->version, 'assigned_user_id' => $foreign->id, 'department_id' => null])->assertUnprocessable();
    $this->postJson($url, ['version' => $conversation->version, 'assigned_user_id' => null, 'department_id' => $foreignDepartment->id])->assertUnprocessable();
    $this->postJson($url, ['version' => $conversation->version, 'assigned_user_id' => $mismatched->id, 'department_id' => $department->id])->assertUnprocessable();
    $this->postJson($url, ['version' => $conversation->version, 'assigned_user_id' => $operator->id, 'department_id' => $department->id])->assertOk()->assertJsonPath('data.assigned_user_id', $operator->id)->assertJsonPath('data.status', 'open');
    $operator->is_active = false;
    $operator->save();
    $this->postJson($url, ['version' => $conversation->fresh()->version, 'assigned_user_id' => $operator->id, 'department_id' => $department->id])->assertUnprocessable();
});

it('closes and returns a dialog to the queue with an audited reason', function () {
    [, $owner, $conversation] = inboxFixture();
    $url = '/api/v1/conversations/'.$conversation->id;
    $this->actingAs($owner)->postJson($url.'/transition', ['version' => $conversation->version, 'status' => 'open'])->assertStatus(409);
    $this->postJson($url.'/accept', ['version' => $conversation->version])->assertOk();
    $this->postJson($url.'/transition', ['version' => $conversation->fresh()->version, 'status' => 'closed', 'reason' => 'Вопрос решён'])->assertOk()->assertJsonPath('data.status', 'closed');
    $this->postJson($url.'/messages', ['body' => 'Нельзя', 'client_message_id' => (string) Str::uuid()])->assertStatus(409);
    $this->postJson($url.'/transition', ['version' => $conversation->fresh()->version, 'status' => 'pending'])->assertOk()->assertJsonPath('data.assigned_user_id', null);
    expect(ConversationActivity::withoutGlobalScopes()->where('reason', 'Вопрос решён')->count())->toBe(1);
});

it('stores tenant quick replies and prohibits operator administration and cross-tenant edits', function () {
    [$org, $owner] = inboxFixture();
    $this->actingAs($owner)->postJson('/api/v1/quick-replies', ['title' => 'Приветствие', 'body' => 'Добрый день!'])->assertCreated();
    $reply = QuickReply::withoutGlobalScopes()->firstOrFail();
    $this->patchJson('/api/v1/quick-replies/'.$reply->id, ['title' => 'Приветствие', 'body' => 'Здравствуйте!'])->assertOk();
    $operator = User::factory()->create(['organization_id' => $org->id, 'role' => Role::Operator]);
    $this->actingAs($operator)->getJson('/api/v1/quick-replies')->assertJsonPath('data.0.body', 'Здравствуйте!');
    $this->postJson('/api/v1/quick-replies', ['title' => 'Нельзя', 'body' => 'Текст'])->assertForbidden();
    $foreignOrg = Organization::create(['name' => 'Чужая']);
    $foreign = User::factory()->create(['organization_id' => $foreignOrg->id, 'role' => Role::Owner]);
    $this->actingAs($foreign)->patchJson('/api/v1/quick-replies/'.$reply->id, ['title' => 'Взлом', 'body' => 'Текст'])->assertNotFound();
    $this->getJson('/api/v1/quick-replies')->assertJsonCount(0, 'data');
});

it('paginates older operator history without loading or skipping the entire conversation', function () {
    [$org, $owner, $conversation] = inboxFixture();
    $rows = [];
    for ($sequence = 2; $sequence <= 205; $sequence++) {
        $rows[] = ['organization_id' => $org->id, 'conversation_id' => $conversation->id, 'sequence' => $sequence, 'sender_key' => 'visitor:probe', 'sender_type' => 'visitor', 'kind' => 'message', 'body' => 'History '.$sequence, 'client_message_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()];
    }
    DB::table('messages')->insert($rows);
    Conversation::withoutGlobalScopes()->whereKey($conversation->id)->update(['last_sequence' => 205, 'last_public_sequence' => 205]);
    $url = '/api/v1/conversations/'.$conversation->id.'/messages';
    $this->actingAs($owner)->getJson($url.'?latest=1')->assertJsonCount(100, 'data')->assertJsonPath('data.0.sequence', 106)->assertJsonPath('meta.has_older', true)->assertJsonPath('meta.next_after_sequence', 205);
    $this->getJson($url.'?before_sequence=106')->assertJsonCount(100, 'data')->assertJsonPath('data.0.sequence', 6)->assertJsonPath('meta.next_before_sequence', 6);
    $this->getJson($url.'?before_sequence=6')->assertJsonCount(5, 'data')->assertJsonPath('data.0.sequence', 1)->assertJsonPath('meta.has_older', false);
});

it('publishes notifications only to operators who can view the conversations department', function () {
    [$org, $owner, $conversation] = inboxFixture();
    app(TenantContext::class)->set($org->id);
    $sales = Department::create(['name' => 'Продажи']);
    $service = Department::create(['name' => 'Сервис']);
    app(TenantContext::class)->clear();
    $allowed = User::factory()->create(['organization_id' => $org->id, 'role' => Role::Operator, 'department_id' => $sales->id]);
    User::factory()->create(['organization_id' => $org->id, 'role' => Role::Operator, 'department_id' => $service->id]);
    Conversation::withoutGlobalScopes()->whereKey($conversation->id)->update(['department_id' => $sales->id, 'assigned_user_id' => $allowed->id, 'status' => 'open']);
    $event = ChatEvent::withoutGlobalScopes()->firstOrFail();
    Event::fake([ChatMessageAvailable::class]);
    (new PublishChatEvent($org->id, $event->id))->handle();
    Event::assertDispatched(ChatMessageAvailable::class, function ($event) use ($org, $owner, $allowed) {
        $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());
        sort($channels);
        $expected = ['private-organizations.'.$org->id.'.operators.'.$owner->id, 'private-organizations.'.$org->id.'.operators.'.$allowed->id];
        sort($expected);

        return $channels === $expected && ! isset($event->broadcastWith()['data']['body']);
    });
});

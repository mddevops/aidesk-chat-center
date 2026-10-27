<?php

use App\Chat\Actions\SendMessage;
use App\Enums\Role;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationActivity;
use App\Models\Department;
use App\Models\OperatorState;
use App\Models\RoutingRule;
use App\Models\User;
use App\Models\VisitorSession;
use App\Routing\RouteConversation;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function routingFixture(): array
{
    Queue::fake();
    [$org, $owner, $channel] = chatTenant();
    app(TenantContext::class)->set($org->id);
    $operators = User::factory()->count(3)->create(['organization_id' => $org->id, 'role' => Role::Operator]);
    foreach ($operators as $operator) {
        OperatorState::create(['user_id' => $operator->id, 'status' => 'available', 'heartbeat_at' => now(), 'capacity' => 2]);
    }
    $rule = RoutingRule::create(['channel_id' => $channel->id, 'scope_key' => 'global', 'strategy' => 'round_robin', 'operator_ids' => [$operators[0]->id, $operators[1]->id], 'fallback_user_id' => $operators[2]->id]);
    app(TenantContext::class)->clear();

    return [$org, $owner, $channel, $operators, $rule];
}

function routingMessage($test, $channel): Conversation
{
    [$base] = chatBrowser($test, $channel);
    chatSend($test, $base)->assertCreated();

    return Conversation::withoutGlobalScopes()->latest('id')->firstOrFail();
}

it('rotates eligible operators atomically, respects capacity, uses reserve and leaves overflow queued', function () {
    [$org, , $channel, $operators] = routingFixture();
    $assigned = [];
    for ($i = 0; $i < 7; $i++) {
        $assigned[] = routingMessage($this, $channel)->assigned_user_id;
    }
    expect($assigned)->toBe([$operators[0]->id, $operators[1]->id, $operators[0]->id, $operators[1]->id, $operators[2]->id, $operators[2]->id, null]);
    expect(ConversationActivity::withoutGlobalScopes()->where('type', 'route')->count())->toBe(6);
    app(TenantContext::class)->set($org->id);
    $queued = Conversation::latest('id')->firstOrFail();
    app(RouteConversation::class)->handle($queued);
    expect($queued->fresh()->assigned_user_id)->toBeNull();
    app(TenantContext::class)->clear();
});

it('excludes stale away off-shift disabled foreign-department operators and recovers a waiting queue', function () {
    [$org, , $channel, $operators] = routingFixture();
    app(TenantContext::class)->set($org->id);
    OperatorState::where('user_id', $operators[0]->id)->update(['heartbeat_at' => now()->subSeconds(91)]);
    OperatorState::where('user_id', $operators[1]->id)->update(['status' => 'away']);
    OperatorState::where('user_id', $operators[2]->id)->first()->update(['schedule' => []]);
    app(TenantContext::class)->clear();
    $waiting = routingMessage($this, $channel);
    expect($waiting->assigned_user_id)->toBeNull();
    app(TenantContext::class)->set($org->id);
    $local = now()->timezone($org->fresh()->timezone);
    OperatorState::where('user_id', $operators[1]->id)->first()->update(['status' => 'available', 'heartbeat_at' => now(), 'schedule' => [['day' => $local->isoWeekday(), 'start' => '00:00', 'end' => '23:59']]]);
    $this->artisan('aidesk:route-pending')->assertSuccessful();
    expect($waiting->fresh()->assigned_user_id)->toBe($operators[1]->id);
    app(RouteConversation::class)->handle($waiting);
    expect(ConversationActivity::where('conversation_id', $waiting->id)->count())->toBe(1);
    $operators[1]->is_active = false;
    $operators[1]->save();
    app(TenantContext::class)->clear();
    expect(routingMessage($this, $channel)->assigned_user_id)->toBeNull();
});

it('chooses least load and never assigns across tenant or department even after a rule becomes stale', function () {
    [$org, $owner, $channel, $operators, $rule] = routingFixture();
    routingMessage($this, $channel);
    app(TenantContext::class)->set($org->id);
    $rule->update(['strategy' => 'least_load']);
    app(TenantContext::class)->clear();
    expect(routingMessage($this, $channel)->assigned_user_id)->toBe($operators[1]->id);
    app(TenantContext::class)->set($org->id);
    $department = Department::create(['name' => 'Продажи']);
    $rule->update(['department_id' => $department->id, 'scope_key' => 'department:'.$department->id]);
    $contact = Contact::firstOrFail();
    $session = VisitorSession::create(['department_id' => $department->id, 'channel_id' => $channel->id, 'contact_id' => $contact->id, 'token_hash' => hash('sha256', random_bytes(32)), 'expires_at' => now()->addDay()]);
    [$message] = app(SendMessage::class)->visitor($session, ['body' => 'Отдел', 'kind' => 'message', 'attachment_ids' => [], 'client_message_id' => (string) Str::uuid()]);
    expect(Conversation::findOrFail($message->conversation_id)->assigned_user_id)->toBeNull();
    app(TenantContext::class)->clear();
    [$foreignOrg, $foreignOwner] = chatTenant();
    $this->actingAs($foreignOwner)->putJson('/api/v1/routing/channels/'.$channel->id, ['strategy' => 'round_robin', 'operator_ids' => [], 'is_active' => true, 'revision' => 1])->assertNotFound();
    $this->actingAs($owner)->putJson('/api/v1/routing/channels/'.$channel->id, ['strategy' => 'round_robin', 'operator_ids' => [$foreignOwner->id], 'is_active' => true, 'revision' => 0])->assertUnprocessable();
});

it('guards management, validates schedules and versions status changes across browser tabs', function () {
    [$org, $owner, $channel, $operators, $rule] = routingFixture();
    $this->actingAs($operators[0])->get('/settings/routing')->assertForbidden();
    $this->putJson('/api/v1/routing/operators/'.$operators[0]->id, ['capacity' => 3, 'schedule' => null])->assertForbidden();
    $this->postJson('/api/v1/availability', ['status' => 'away', 'revision' => 0])->assertOk()->assertJsonPath('data.revision', 1);
    $this->postJson('/api/v1/availability', ['status' => 'available', 'revision' => 0])->assertStatus(409);
    $this->postJson('/api/v1/availability', ['status' => 'available', 'revision' => 1])->assertOk()->assertJsonPath('data.revision', 2);
    $this->travel(91)->seconds();
    $this->getJson('/api/v1/availability')->assertJsonPath('data.status', 'offline');
    $this->actingAs($owner)->putJson('/api/v1/routing/operators/'.$operators[0]->id, ['capacity' => 0, 'schedule' => [['day' => 1, 'start' => '18:00', 'end' => '09:00']]])->assertUnprocessable();
    $this->putJson('/api/v1/routing/operators/'.$operators[0]->id, ['capacity' => 3, 'schedule' => null])->assertOk();
    $data = ['strategy' => 'least_load', 'operator_ids' => [$operators[0]->id], 'is_active' => true, 'revision' => 1];
    $this->putJson('/api/v1/routing/channels/'.$channel->id, $data)->assertOk()->assertJsonPath('data.revision', 2);
    $this->putJson('/api/v1/routing/channels/'.$channel->id, $data)->assertStatus(409);
});

it('marks the operator offline on logout and invalidates old tab heartbeat revisions', function () {
    [, , , $operators] = routingFixture();
    $operator = $operators[0];
    $this->actingAs($operator)->post('/logout')->assertRedirect('/login');
    $state = OperatorState::withoutGlobalScopes()->where('user_id', $operator->id)->firstOrFail();
    expect($state->status)->toBe('offline')->and($state->revision)->toBe(1);
    $this->actingAs($operator)->postJson('/api/v1/availability', ['status' => 'available', 'revision' => 0])->assertStatus(409);
});

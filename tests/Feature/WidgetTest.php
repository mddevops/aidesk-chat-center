<?php

use App\Enums\Role;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Showroom;
use App\Models\Site;
use App\Models\User;
use App\Models\VisitorSession;
use App\Models\WidgetVersion;
use App\Tenancy\TenantContext;
use App\Widgets\WidgetConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function widgetOwner(): array
{
    $organization = Organization::create(['name' => 'Widgets']);
    $owner = User::factory()->create(['organization_id' => $organization->id, 'role' => Role::Owner]);
    app(TenantContext::class)->set($organization->id);
    $salon = Showroom::create(['name' => 'Салон']);
    $site = Site::create(['name' => 'Сайт', 'origin' => 'https://widget.example', 'showroom_id' => $salon->id]);
    app(TenantContext::class)->clear();

    return [$owner, $site];
}

it('publishes immutable snapshots and keeps draft changes off the public website', function () {
    [$owner, $site] = widgetOwner();
    $this->actingAs($owner);
    $widget = $this->postJson('/api/v1/widgets/sites/'.$site->id)->assertOk()->assertJsonPath('data.revision', 1)->json('data');
    $this->postJson('/api/v1/widgets/sites/'.$site->id)->assertOk()->assertJsonPath('data.id', $widget['id']);
    $public = '/widget/v1/channels/'.$widget['channel_public_id'];
    $this->getJson($public.'/configuration')->assertNotFound();
    $this->postJson('/api/v1/widgets/'.$widget['id'].'/publish', ['revision' => 1])->assertOk()->assertJsonPath('data.published_version', 1);
    $config = WidgetConfiguration::defaults();
    $config['name'] = 'Новый черновик';
    $this->putJson('/api/v1/widgets/'.$widget['id'], ['revision' => 2, 'configuration' => $config])->assertOk()->assertJsonPath('data.revision', 3);
    $this->getJson($public.'/configuration', ['Origin' => $site->origin])->assertOk()->assertHeader('Access-Control-Allow-Origin', $site->origin)->assertJsonPath('data.configuration.name', 'Помощник автосалона');
    $this->postJson('/api/v1/widgets/'.$widget['id'].'/publish', ['revision' => 2])->assertStatus(409)->assertJsonPath('error.code', 'version_conflict');
    $this->putJson('/api/v1/widgets/'.$widget['id'], ['revision' => 2, 'configuration' => $config])->assertStatus(409);
    $this->postJson('/api/v1/widgets/'.$widget['id'].'/publish', ['revision' => 3])->assertOk()->assertJsonCount(2, 'data.versions');
    $this->getJson($public.'/configuration')->assertJsonPath('data.configuration.name', 'Новый черновик')->assertJsonMissingPath('data.configuration.consent_version');
    expect(WidgetVersion::withoutGlobalScopes()->count())->toBe(2);
});

it('isolates widget management and public origins and prevents unsafe configuration', function () {
    [$owner, $site] = widgetOwner();
    $widget = $this->actingAs($owner)->postJson('/api/v1/widgets/sites/'.$site->id)->json('data');
    $otherOrg = Organization::create(['name' => 'Другой tenant']);
    $foreign = User::factory()->create(['organization_id' => $otherOrg->id, 'role' => Role::Owner]);
    $this->actingAs($foreign)->getJson('/api/v1/widgets/'.$widget['id'])->assertNotFound();
    $this->postJson('/api/v1/widgets/sites/'.$site->id)->assertNotFound();
    $operator = User::factory()->create(['organization_id' => $owner->organization_id, 'role' => Role::Operator]);
    $this->actingAs($operator)->get('/settings/messenger')->assertForbidden();
    $this->getJson('/api/v1/widgets/'.$widget['id'])->assertForbidden();
    $config = [...WidgetConfiguration::defaults(), 'color' => 'red;position:fixed', 'logo_url' => 'javascript:alert(1)', 'privacy_url' => 'http://insecure.example', 'width' => 10000];
    $this->actingAs($owner)->putJson('/api/v1/widgets/'.$widget['id'], ['revision' => 1, 'configuration' => $config])->assertUnprocessable()->assertJsonValidationErrors(['color', 'logo_url', 'privacy_url', 'width']);
    $this->postJson('/api/v1/widgets/'.$widget['id'].'/publish', ['revision' => 1])->assertOk();
    $url = '/widget/v1/channels/'.$widget['channel_public_id'];
    $this->getJson($url.'/configuration', ['Origin' => 'https://attacker.example'])->assertForbidden();
    $this->get($url.'/frame')->assertOk()->assertHeader('Content-Security-Policy', "frame-ancestors https://widget.example; object-src 'none'; base-uri 'none'")->assertHeader('Referrer-Policy', 'no-referrer');
    expect(Contact::withoutGlobalScopes()->count())->toBe(0)->and(VisitorSession::withoutGlobalScopes()->count())->toBe(0);
});

it('validates department choices against the published tenant configuration before contact creation', function () {
    [$owner, $site] = widgetOwner();
    app(TenantContext::class)->set($owner->organization_id);
    $department = Department::create(['name' => 'Продажи']);
    $otherDepartment = Department::create(['name' => 'Сервис']);
    app(TenantContext::class)->clear();
    $widget = $this->actingAs($owner)->postJson('/api/v1/widgets/sites/'.$site->id)->json('data');
    $config = [...WidgetConfiguration::defaults(), 'department_ids' => [$department->id]];
    $this->putJson('/api/v1/widgets/'.$widget['id'], ['revision' => 1, 'configuration' => $config])->assertOk();
    $this->postJson('/api/v1/widgets/'.$widget['id'].'/publish', ['revision' => 2])->assertOk();
    $base = '/widget/v1/channels/'.$widget['channel_public_id'];
    $this->getJson($base.'/configuration')->assertJsonPath('data.configuration.departments.0.name', 'Продажи');
    $token = $this->postJson($base.'/sessions', [], ['Origin' => $site->origin])->json('data.credential');
    $headers = ['Authorization' => 'Bearer '.$token, 'Origin' => $site->origin];
    $identity = ['name' => 'Иван', 'phone' => '89991234567', 'consent' => true, 'consent_version' => 'contact-v1'];
    $this->postJson($base.'/identify', $identity, $headers)->assertUnprocessable();
    $this->postJson($base.'/identify', [...$identity, 'department_id' => $otherDepartment->id], $headers)->assertUnprocessable();
    expect(Contact::withoutGlobalScopes()->count())->toBe(0);
    $this->postJson($base.'/identify', [...$identity, 'department_id' => $department->id], $headers)->assertOk();
    Queue::fake();
    $this->postJson($base.'/messages', ['body' => 'Вопрос в продажи', 'client_message_id' => (string) Str::uuid()], $headers)->assertCreated();
    expect(Conversation::withoutGlobalScopes()->first()->department_id)->toBe($department->id);
    app(TenantContext::class)->set($owner->organization_id);
    $snapshot = WidgetVersion::firstOrFail();
    expect(fn () => $snapshot->update(['configuration' => []]))->toThrow(LogicException::class);
    app(TenantContext::class)->clear();
});

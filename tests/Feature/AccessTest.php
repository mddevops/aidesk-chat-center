<?php

use App\Enums\Role;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Showroom;
use App\Models\Site;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function accessOrganization(string $name = 'Тестовый автосалон', Role $role = Role::Owner): array
{
    $organization = Organization::create(['name' => $name]);
    $user = User::factory()->create(['organization_id' => $organization->id, 'role' => $role, 'password' => 'SecurePassword123']);

    return [$organization, $user];
}

function withinOrganization(int $id, Closure $callback): mixed
{
    $context = app(TenantContext::class);
    $context->clear();
    $context->set($id);
    try {
        return $callback();
    } finally {
        $context->clear();
    }
}

it('authenticates a provisioned user, regenerates session and logs out', function () {
    [, $owner] = accessOrganization();
    $this->get('/settings/organization')->assertRedirect('/login');
    $this->post('/login', ['email' => $owner->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    $this->assertGuest();
    $this->post('/login', ['email' => strtoupper($owner->email), 'password' => 'SecurePassword123'])->assertRedirect('/settings/organization');
    $this->assertAuthenticatedAs($owner);
    $this->get('/settings/organization')->assertOk();
    $this->post('/logout')->assertRedirect('/login');
    $this->assertGuest();
});

it('rejects disabled and unprovisioned accounts and limits password guessing', function () {
    [, $owner] = accessOrganization();
    $owner->is_active = false;
    $owner->save();
    for ($i = 0; $i < 6; $i++) {
        $this->post('/login', ['email' => $owner->email, 'password' => 'SecurePassword123'])->assertSessionHasErrors('email');
    }
    $this->assertGuest();
    $this->actingAs($owner)->get('/settings/organization')->assertForbidden();
    $unprovisioned = User::factory()->create();
    $this->actingAs($unprovisioned)->get('/settings/organization')->assertForbidden();
});

it('fails closed without a tenant and rejects writes into another organization', function () {
    [$a] = accessOrganization('А');
    [$b] = accessOrganization('Б');
    $salonA = withinOrganization($a->id, fn () => Showroom::create(['name' => 'А-салон']));
    withinOrganization($b->id, fn () => Showroom::create(['name' => 'Б-салон']));
    expect(Showroom::count())->toBe(0);
    expect(fn () => Showroom::create(['name' => 'Без контекста']))->toThrow(LogicException::class);
    withinOrganization($b->id, function () use ($salonA) {
        expect(Showroom::pluck('name')->all())->toBe(['Б-салон']);
        expect(fn () => $salonA->update(['name' => 'Взлом']))->toThrow(LogicException::class);
    });
});

it('isolates directory queries, route binding and policies from a second tenant', function () {
    [$a, $ownerA] = accessOrganization('А');
    [$b, $ownerB] = accessOrganization('Б');
    $salonB = withinOrganization($b->id, fn () => Showroom::create(['name' => 'Чужой салон']));
    withinOrganization($a->id, fn () => Showroom::create(['name' => 'Свой салон']));
    $this->actingAs($ownerA)->get('/settings/organization')->assertInertia(fn (Assert $page) => $page
        ->component('Settings/Organization')->has('showrooms', 1)->where('showrooms.0.name', 'Свой салон')
        ->has('members', 1)->where('members.0.id', $ownerA->id));
    $this->patch('/settings/showrooms/'.$salonB->id, ['name' => 'Взлом'])->assertNotFound();
    expect(Gate::forUser($ownerA)->allows('update', $salonB))->toBeFalse();
    expect(Gate::forUser($ownerB)->allows('update', $salonB))->toBeTrue();
    expect(app(TenantContext::class)->id())->toBeNull();
});

it('creates and updates its own directories while ignoring a forged tenant id', function () {
    [$a, $owner] = accessOrganization();
    [$b] = accessOrganization('Другая');
    $this->actingAs($owner)->post('/settings/showrooms', ['name' => 'Новый салон', 'organization_id' => $b->id])->assertSessionHasNoErrors()->assertRedirect();
    $salon = withinOrganization($a->id, fn () => Showroom::firstOrFail());
    expect((int) $salon->organization_id)->toBe($a->id);
    $this->patch('/settings/showrooms/'.$salon->id, ['name' => 'Обновлённый'])->assertSessionHasNoErrors();
    $this->post('/settings/departments', ['name' => 'Продажи'])->assertSessionHasNoErrors();
    $this->post('/settings/sites', ['name' => 'Основной', 'origin' => 'https://dealer.example/', 'showroom_id' => $salon->id])->assertSessionHasNoErrors();
    expect(withinOrganization($a->id, fn () => Site::firstOrFail()->origin))->toBe('https://dealer.example');
});

it('rejects cross-tenant relationships and origins containing paths or credentials', function () {
    [$a, $owner] = accessOrganization('А');
    [$b] = accessOrganization('Б');
    $salonB = withinOrganization($b->id, fn () => Showroom::create(['name' => 'Чужой']));
    $this->actingAs($owner)->post('/settings/sites', ['name' => 'Ошибка', 'origin' => 'https://dealer.example', 'showroom_id' => $salonB->id])->assertSessionHasErrors('showroom_id');
    $salonA = withinOrganization($a->id, fn () => Showroom::create(['name' => 'Свой']));
    foreach (['https://dealer.example/path', 'https://user:pass@dealer.example', 'https://dealer.example?secret=1', 'javascript:alert(1)'] as $origin) {
        $this->post('/settings/sites', ['name' => 'Ошибка', 'origin' => $origin, 'showroom_id' => $salonA->id])->assertSessionHasErrors('origin');
    }
});

it('denies operator administration and hides the employee directory', function () {
    [, $operator] = accessOrganization(role: Role::Operator);
    $this->actingAs($operator)->get('/settings/organization')->assertInertia(fn (Assert $page) => $page->has('members', 0));
    $this->post('/settings/showrooms', ['name' => 'Запрещено'])->assertForbidden();
    $this->patch('/settings/organization', ['name' => 'Взлом', 'timezone' => 'UTC'])->assertForbidden();
    $this->post('/settings/members', [])->assertForbidden();
});

it('creates scoped employees with hashed passwords and prevents role escalation', function () {
    [$a, $owner] = accessOrganization();
    $this->actingAs($owner)->post('/settings/members', [
        'name' => 'Оператор', 'email' => 'operator@example.test', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123', 'role' => 'operator',
    ])->assertSessionHasNoErrors();
    $operator = User::where('email', 'operator@example.test')->firstOrFail();
    expect($operator->organization_id)->toBe($a->id);
    expect(Hash::check('NewPassword123', $operator->password))->toBeTrue();
    expect($operator->toArray())->not->toHaveKeys(['password', 'remember_token']);
    $this->patch('/settings/members/'.$owner->id, ['role' => 'operator', 'is_active' => false])->assertForbidden();
    $admin = User::factory()->create(['organization_id' => $a->id, 'role' => Role::Admin]);
    $this->actingAs($admin)->patch('/settings/members/'.$operator->id, ['role' => 'admin', 'is_active' => true])->assertSessionHasErrors('role');
    $this->patch('/settings/members/'.$operator->id, ['role' => 'owner', 'is_active' => true])->assertSessionHasErrors('role');
});

it('rejects foreign employee updates and foreign department assignments', function () {
    [$a, $ownerA] = accessOrganization('А');
    [$b, $ownerB] = accessOrganization('Б');
    $departmentB = withinOrganization($b->id, fn () => Department::create(['name' => 'Чужой отдел']));
    $this->actingAs($ownerA)->patch('/settings/members/'.$ownerB->id, ['role' => 'operator', 'is_active' => true])->assertNotFound();
    $operatorA = User::factory()->create(['organization_id' => $a->id, 'role' => Role::Operator]);
    $this->patch('/settings/members/'.$operatorA->id, ['role' => 'operator', 'is_active' => true, 'department_id' => $departmentB->id])->assertSessionHasErrors('department_id');
});

it('authorizes only the active operators own tenant and user realtime channel', function () {
    [$a, $owner] = accessOrganization('А');
    [$b] = accessOrganization('Б');
    config(['broadcasting.default' => 'reverb']);
    $payload = ['socket_id' => '123.456', 'channel_name' => 'private-organizations.'.$a->id.'.operators.'.$owner->id];
    $this->actingAs($owner)->post('/broadcasting/auth', $payload)->assertOk()->assertJsonStructure(['auth']);
    $this->post('/broadcasting/auth', [...$payload, 'channel_name' => 'private-organizations.'.$b->id.'.operators.'.$owner->id])->assertForbidden();
    $this->post('/broadcasting/auth', [...$payload, 'channel_name' => 'private-organizations.'.$a->id.'.operators.999999'])->assertForbidden();
    $owner->is_active = false;
    $owner->save();
    $this->post('/broadcasting/auth', $payload)->assertForbidden();
});

it('provisions an organization and owner with an interactive hidden password', function () {
    $this->artisan('aidesk:organization', ['name' => 'Новая организация', 'email' => 'owner@example.test'])
        ->expectsQuestion('Пароль владельца (минимум 12 символов, буквы и цифры)', 'OwnerPassword123')
        ->assertSuccessful();
    $owner = User::where('email', 'owner@example.test')->firstOrFail();
    expect($owner->role)->toBe(Role::Owner);
    expect($owner->organization_id)->not->toBeNull();
});

it('persists organization settings and revokes an employees access on the next request', function () {
    [$organization, $owner] = accessOrganization();
    $employee = User::factory()->create(['organization_id' => $organization->id, 'role' => Role::Operator]);
    $this->actingAs($owner)->patch('/settings/organization', ['name' => 'Обновлённая организация', 'timezone' => 'Asia/Yekaterinburg'])->assertSessionHasNoErrors();
    expect($organization->fresh()->timezone)->toBe('Asia/Yekaterinburg');
    $this->patch('/settings/organization', ['name' => 'Ошибка', 'timezone' => 'Not/A_Timezone'])->assertSessionHasErrors('timezone');
    $this->patch('/settings/members/'.$employee->id, ['role' => 'operator', 'is_active' => false])->assertSessionHasNoErrors();
    expect($employee->fresh()->is_active)->toBeFalse();
    $this->actingAs($employee->fresh())->get('/settings/organization')->assertForbidden();
});

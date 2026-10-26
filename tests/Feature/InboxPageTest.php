<?php

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('guards the operator page and shares only safe tenant metadata', function () {
    $organization = Organization::create(['name' => 'Inbox organization']);
    $user = User::factory()->create(['organization_id' => $organization->id, 'role' => Role::Operator]);
    $this->get('/inbox')->assertRedirect('/login');
    $this->actingAs($user)->get('/inbox')->assertInertia(fn (Assert $page) => $page->component('Inbox/Index')->where('organization.id', $organization->id)->missing('auth.user.password')->missing('reverbSecret'));
    $user->role = Role::Analyst;
    $user->save();
    $this->get('/inbox')->assertForbidden();
});

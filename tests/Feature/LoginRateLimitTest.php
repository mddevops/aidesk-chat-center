<?php

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows different operators behind one office IP to sign in without sharing an account quota', function () {
    $org = Organization::create(['name' => 'Shared office']);
    $users = collect(range(1, 3))->map(fn ($i) => User::factory()->create(['organization_id' => $org->id, 'role' => Role::Operator, 'email' => 'office-'.$i.'@example.test', 'password' => 'OfficePassword123']));
    for ($i = 0; $i < 21; $i++) {
        $this->post('/login', ['email' => $users[$i % 3]->email, 'password' => 'OfficePassword123'])->assertRedirect('/settings/organization');
        $this->post('/logout')->assertRedirect('/login');
    }
});

it('still limits repeated sign-ins to one normalized account', function () {
    $org = Organization::create(['name' => 'Login quota']);
    $user = User::factory()->create(['organization_id' => $org->id, 'role' => Role::Operator, 'password' => 'OfficePassword123']);
    for ($i = 0; $i < 20; $i++) {
        $this->post('/login', ['email' => $user->email, 'password' => 'OfficePassword123'])->assertRedirect('/settings/organization');
        $this->post('/logout');
    }
    $this->post('/login', ['email' => strtoupper($user->email), 'password' => 'OfficePassword123'])->assertStatus(429)->assertHeader('Retry-After');
});

it('bounds attempts across different accounts from one IP', function () {
    for ($i = 0; $i < 60; $i++) {
        $this->post('/login', ['email' => 'unknown-'.$i.'@example.test', 'password' => 'incorrect'])->assertSessionHasErrors('email');
    }
    $this->post('/login', ['email' => 'another@example.test', 'password' => 'incorrect'])->assertStatus(429)->assertHeader('Retry-After');
});

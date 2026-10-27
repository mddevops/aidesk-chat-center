<?php

use App\Enums\Role;
use App\Models\Channel;
use App\Models\Organization;
use App\Models\Showroom;
use App\Models\Site;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;

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

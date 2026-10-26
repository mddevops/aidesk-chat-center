<?php

use App\Chat\Actions\SendMessage;
use App\Events\ChatMessageAvailable;
use App\Jobs\PublishChatEvent;
use App\Models\Channel;
use App\Models\ChatEvent;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Showroom;
use App\Models\Site;
use App\Models\VisitorSession;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

function chatInfrastructureFixture(): array
{
    $organization = Organization::create(['name' => 'Chat infrastructure '.Str::uuid()]);
    app(TenantContext::class)->set($organization->id);
    $showroom = Showroom::create(['name' => 'Test showroom']);
    $site = Site::create(['name' => 'Test site', 'origin' => 'https://probe.example.test', 'showroom_id' => $showroom->id]);
    $channel = Channel::create(['name' => 'Test channel', 'site_id' => $site->id, 'kind' => 'website']);
    $contact = Contact::create(['name' => 'Synthetic probe', 'phone' => '+79990000000']);
    $session = VisitorSession::create(['channel_id' => $channel->id, 'contact_id' => $contact->id, 'token_hash' => hash('sha256', random_bytes(32)), 'expires_at' => now()->addHour(), 'consented_at' => now(), 'consent_version' => 'contact-v1']);

    return [$organization->id, $session->id];
}

function cleanupChatInfrastructureFixture(int $id): void
{
    // Delete only records created in this synthetic probe organization; never truncate shared tables.
    foreach (['chat_events', 'conversation_reads', 'attachments', 'messages', 'conversations', 'visitor_sessions', 'contacts', 'channels', 'sites', 'showrooms'] as $table) {
        DB::table($table)->where('organization_id', $id)->delete();
    }
    DB::table('organizations')->where('id', $id)->delete();
    app(TenantContext::class)->clear();
}

it('commits MySQL messages and outbox rows when the queue cannot accept a job', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    [$tenant, $sessionId] = chatInfrastructureFixture();
    try {
        Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('Simulated Redis queue outage'));
        [$message, $created] = app(SendMessage::class)->visitor(VisitorSession::findOrFail($sessionId), ['body' => 'Durable test', 'kind' => 'message', 'attachment_ids' => [], 'client_message_id' => (string) Str::uuid()]);
        expect($created)->toBeTrue();
        expect(Message::findOrFail($message->id)->body)->toBe('Durable test');
        $event = ChatEvent::firstOrFail();
        expect($event->published_at)->toBeNull();
        Event::fake([ChatMessageAvailable::class]);
        (new PublishChatEvent($tenant, $event->id))->handle();
        expect($event->fresh()->published_at)->not->toBeNull();
    } finally {
        cleanupChatInfrastructureFixture($tenant);
    }
})->group('infrastructure');

it('serializes concurrent first-message retries and preserves MySQL sequence uniqueness', function () {
    [$tenant, $sessionId] = chatInfrastructureFixture();
    try {
        $payload = ['body' => 'Concurrent retry', 'kind' => 'message', 'attachment_ids' => [], 'client_message_id' => (string) Str::uuid()];
        $work = function () use ($tenant, $sessionId, $payload) {
            app(TenantContext::class)->set($tenant);
            try {
                [$message, $created] = app(SendMessage::class)->visitor(VisitorSession::findOrFail($sessionId), $payload);

                return ['id' => $message->id, 'created' => $created];
            } finally {
                app(TenantContext::class)->clear();
            }
        };
        $work = $work->bindTo(null, null);
        $results = Concurrency::driver('process')->run([$work, $work, $work, $work]);
        expect(count(array_unique(array_column($results, 'id'))))->toBe(1);
        expect(count(array_filter(array_column($results, 'created'))))->toBe(1);
        expect(Conversation::count())->toBe(1);
        expect(Message::count())->toBe(1);
        expect(Message::firstOrFail()->sequence)->toBe(1);
        expect(ChatEvent::count())->toBe(1);
        $tasks = [];
        for ($index = 0; $index < 4; $index++) {
            $next = ['body' => 'Concurrent '.$index, 'kind' => 'message', 'attachment_ids' => [], 'client_message_id' => (string) Str::uuid()];
            $task = function () use ($tenant, $sessionId, $next) {
                app(TenantContext::class)->set($tenant);
                try {
                    return app(SendMessage::class)->visitor(VisitorSession::findOrFail($sessionId), $next)[0]->sequence;
                } finally {
                    app(TenantContext::class)->clear();
                }
            };
            $tasks[] = $task->bindTo(null, null);
        }
        $sequences = Concurrency::driver('process')->run($tasks);
        sort($sequences);
        expect($sequences)->toBe([2, 3, 4, 5]);
        expect(Message::count())->toBe(5);
    } finally {
        cleanupChatInfrastructureFixture($tenant);
    }
})->group('infrastructure');

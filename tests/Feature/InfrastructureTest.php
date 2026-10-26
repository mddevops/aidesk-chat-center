<?php

use App\Jobs\CheckQueueConnection;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

it('connects to MySQL 8 and persists migrated records', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    expect(DB::selectOne('select version() as version')->version)->toStartWith('8.');
    $user = User::factory()->create();
    try {
        expect(User::findOrFail($user->id)->email)->toBe($user->email);
    } finally {
        $user->delete();
    }
})->group('infrastructure');

it('uses Redis cache and executes a durable Redis queue job', function () {
    expect(config('queue.default'))->toBe('redis');
    expect(config('cache.default'))->toBe('redis');
    expect((string) Redis::ping())->toBe('PONG');
    $key = 'queue-probe:'.Str::uuid();
    $queue = 'probe-'.Str::uuid();
    Queue::connection('redis')->push(new CheckQueueConnection($key), '', $queue);
    expect(Cache::get($key))->toBeNull();
    Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true, '--tries' => 1]);
    expect(Cache::pull($key))->toBe('processed');
})->group('infrastructure');

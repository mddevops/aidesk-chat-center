<?php

namespace App\Console\Commands;

use App\Jobs\PublishChatEvent;
use App\Models\ChatEvent;
use Illuminate\Console\Command;

class DispatchChatEvents extends Command
{
    protected $signature = 'aidesk:dispatch-chat-events';

    protected $description = 'Поставить недоставленные события чата в очередь';

    public function handle(): int
    {
        // The cross-tenant dispatcher carries each row's explicit organization into a scoped job.
        $events = ChatEvent::withoutGlobalScope('organization')->whereNull('published_at')->where('available_at', '<=', now())->orderBy('id')->limit(500)->get(['id', 'organization_id']);
        foreach ($events as $event) {
            PublishChatEvent::dispatch((int) $event->organization_id, $event->id);
        }
        $this->info('В очередь поставлено событий: '.$events->count());

        return self::SUCCESS;
    }
}

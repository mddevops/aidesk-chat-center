<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Routing\RouteConversation;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

class RoutePendingConversations extends Command
{
    protected $signature = 'aidesk:route-pending';

    protected $description = 'Распределить ожидающие диалоги по действующим правилам';

    public function handle(RouteConversation $router): int
    {
        $context = app(TenantContext::class);
        $previous = $context->id();
        try {
            $maximum = (int) Conversation::withoutGlobalScope('organization')->max('id');
            $processed = 0;
            Conversation::withoutGlobalScope('organization')->where('id', '<=', $maximum)->where('status', 'pending')->whereNull('assigned_user_id')->chunkById(100, function ($rows) use ($context, $router, &$processed) {
                foreach ($rows as $row) {
                    $context->clear();
                    $context->set((int) $row->organization_id);
                    $router->handle($row);
                    $processed++;
                }
            });
        } finally {
            $context->clear();
            if ($previous !== null) {
                $context->set($previous);
            }
        }
        $this->info('Проверено ожидающих диалогов: '.$processed);

        return self::SUCCESS;
    }
}

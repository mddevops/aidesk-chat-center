<?php

namespace App\Http\Middleware;

use App\Chat\ChatException;
use App\Models\Channel;
use App\Models\Site;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

class WidgetChannel
{
    public function handle(Request $request, Closure $next)
    {
        $context = app(TenantContext::class);
        $context->clear();
        // Public UUID bootstrap is the only unscoped channel lookup. It never authorizes history.
        $channel = Channel::withoutGlobalScope('organization')->where('public_id', $request->route('channelPublicId'))->where('kind', 'website')->where('is_active', true)->firstOrFail();
        $context->set((int) $channel->organization_id);
        try {
            $site = Site::findOrFail($channel->site_id);
            $origin = $request->header('Origin');
            $app = parse_url(config('app.url'));
            $appOrigin = ($app['scheme'] ?? 'http').'://'.($app['host'] ?? 'localhost').(isset($app['port']) ? ':'.$app['port'] : '');
            $allowed = [$site->origin];
            if (! $request->isMethod('POST') || ! $request->is('*/sessions')) {
                $allowed[] = $appOrigin;
            }
            if ($origin !== null && ! in_array($origin, $allowed, true)) {
                throw new ChatException('origin_forbidden', 'Сайт не разрешён для этого канала.', 403);
            }
            if ($request->isMethod('POST') && $request->is('*/sessions') && $origin !== $site->origin) {
                throw new ChatException('origin_required', 'Для создания сессии требуется разрешённый Origin.', 403);
            }
            $request->attributes->set('widget_channel', $channel);
            $response = $request->isMethod('OPTIONS') ? response()->noContent() : $next($request);
            if ($origin !== null) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
                $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept');
                $response->headers->set('Access-Control-Expose-Headers', 'X-Request-Id');
                $response->headers->set('Vary', 'Origin', false);
            }
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        } finally {
            $context->clear();
        }
    }
}

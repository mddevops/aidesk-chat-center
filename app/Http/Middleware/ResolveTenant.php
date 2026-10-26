<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        $context = app(TenantContext::class);
        $context->clear();
        $user = $request->user();
        abort_unless($user && $user->is_active && $user->organization_id, 403, 'Нет доступа к организации.');
        $context->set((int) $user->organization_id);
        try {
            $response = $next($request);
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->set('X-Robots-Tag', 'noindex');

            return $response;
        } finally {
            $context->clear();
        }
    }
}

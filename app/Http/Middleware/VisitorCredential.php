<?php

namespace App\Http\Middleware;

use App\Chat\ChatException;
use App\Models\VisitorSession;
use Closure;
use Illuminate\Http\Request;

class VisitorCredential
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new ChatException('invalid_credential', 'Сессия посетителя недействительна.', 401);
        }
        $session = VisitorSession::where('token_hash', hash('sha256', $token))->where('channel_id', $request->attributes->get('widget_channel')->id)
            ->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        if (! $session) {
            throw new ChatException('invalid_credential', 'Сессия посетителя недействительна или истекла.', 401);
        }
        $request->attributes->set('visitor_session', $session);

        return $next($request);
    }
}

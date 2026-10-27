<?php

namespace App\Http\Controllers\Widgets;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class VisitorBroadcastController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate(['socket_id' => ['required', 'string', 'regex:/^\d{1,20}\.\d{1,20}$/D'], 'channel_name' => ['required', 'string', 'max:200']]);
        $session = $request->attributes->get('visitor_session');
        $expected = 'private-organizations.'.$session->organization_id.'.visitors.'.$session->public_id;
        abort_unless(hash_equals($expected, $data['channel_name']), 403);

        // This route uses visitor bearer middleware, never the operator cookie guard.
        return response()->json(Broadcast::connection('reverb')->validAuthenticationResponse($request, true));
    }
}

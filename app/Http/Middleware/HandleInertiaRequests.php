<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request), 'appName' => config('app.name'),
            'auth' => ['user' => $request->user()?->only('id', 'name', 'email', 'role'), 'permissions' => $request->user()?->role->permissions() ?? []],
            'flash' => ['success' => fn () => $request->session()->get('success')],
        ];
    }
}

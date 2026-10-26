<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class InboxController extends Controller
{
    public function __invoke(Request $request)
    {
        Gate::authorize('chat.view');
        $request->validate(['conversation' => ['nullable', 'integer', 'min:1']]);
        $id = app(TenantContext::class)->id();

        return Inertia::render('Inbox/Index', [
            'organization' => Organization::findOrFail($id)->only('id', 'name'),
            'operators' => User::where('organization_id', $id)->where('is_active', true)->whereIn('role', ['owner', 'admin', 'supervisor', 'operator'])->orderBy('name')->get(['id', 'name', 'department_id', 'role']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'initialConversationId' => $request->integer('conversation') ?: null,
        ]);
    }
}

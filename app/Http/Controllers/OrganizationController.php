<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Showroom;
use App\Models\Site;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class OrganizationController extends Controller
{
    public function index(Request $request)
    {
        $id = app(TenantContext::class)->id();
        $manager = $request->user()->hasPermission('team.manage');

        return Inertia::render('Settings/Organization', [
            'organization' => Organization::findOrFail($id)->only('id', 'name', 'timezone'),
            'showrooms' => Showroom::orderBy('name')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'sites' => Site::orderBy('name')->get(['id', 'name', 'origin', 'showroom_id']),
            'members' => $manager ? User::where('organization_id', $id)->orderBy('name')->get(['id', 'name', 'email', 'role', 'is_active', 'department_id']) : [],
            'roles' => collect(Role::cases())->filter(fn ($role) => $role !== Role::Owner && ($role !== Role::Admin || $request->user()->role === Role::Owner))
                ->map(fn ($role) => ['value' => $role->value, 'label' => $role->label()])->values(),
        ]);
    }

    public function update(Request $request)
    {
        Gate::authorize('organization.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'timezone' => ['required', 'timezone']]);
        Organization::findOrFail(app(TenantContext::class)->id())->update($data);

        return back()->with('success', 'Организация обновлена.');
    }

    public function storeShowroom(Request $request)
    {
        Gate::authorize('directory.manage');
        Showroom::create($this->nameData($request, 'showrooms'));

        return back()->with('success', 'Автосалон добавлен.');
    }

    public function updateShowroom(Request $request, Showroom $showroom)
    {
        Gate::authorize('update', $showroom);
        $showroom->update($this->nameData($request, 'showrooms', $showroom->id));

        return back()->with('success', 'Автосалон обновлён.');
    }

    public function storeDepartment(Request $request)
    {
        Gate::authorize('directory.manage');
        Department::create($this->nameData($request, 'departments'));

        return back()->with('success', 'Отдел добавлен.');
    }

    public function updateDepartment(Request $request, Department $department)
    {
        Gate::authorize('update', $department);
        $department->update($this->nameData($request, 'departments', $department->id));

        return back()->with('success', 'Отдел обновлён.');
    }

    public function storeSite(Request $request)
    {
        Gate::authorize('directory.manage');
        Site::create($this->siteData($request));

        return back()->with('success', 'Сайт добавлен.');
    }

    public function updateSite(Request $request, Site $site)
    {
        Gate::authorize('update', $site);
        $site->update($this->siteData($request, $site->id));

        return back()->with('success', 'Сайт обновлён.');
    }

    private function nameData(Request $request, string $table, ?int $id = null): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:150', Rule::unique($table)->where('organization_id', app(TenantContext::class)->id())->ignore($id)]]);
    }

    private function siteData(Request $request, ?int $id = null): array
    {
        $tenant = app(TenantContext::class)->id();
        $origin = rtrim(strtolower(trim((string) $request->input('origin'))), '/');
        $request->merge(['origin' => $origin]);

        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'showroom_id' => ['required', 'integer', Rule::exists('showrooms', 'id')->where('organization_id', $tenant)],
            'origin' => ['required', 'url:http,https', 'max:255', function ($attribute, $value, $fail) {
                $parts = parse_url($value);
                if (! $parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! empty($parts['path'])) {
                    $fail('Укажите только адрес сайта: https://example.ru без пути и параметров.');
                }
            }, Rule::unique('sites')->where('organization_id', $tenant)->ignore($id)],
        ]);
    }

    public function storeMember(Request $request)
    {
        Gate::authorize('team.manage');
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
            ...$this->memberRules($request),
        ]);
        $member = new User;
        $member->fill(collect($data)->only(['name', 'email', 'password'])->all());
        $member->organization_id = app(TenantContext::class)->id();
        $member->role = Role::from($data['role']);
        $member->department_id = $data['department_id'] ?? null;
        $member->save();

        return back()->with('success', 'Сотрудник добавлен.');
    }

    public function updateMember(Request $request, string $member)
    {
        Gate::authorize('team.manage');
        $target = User::where('organization_id', app(TenantContext::class)->id())->findOrFail($member);
        abort_if($target->role === Role::Owner || $target->id === $request->user()->id, 403, 'Изменение владельца или собственной роли запрещено.');
        abort_if($target->role === Role::Admin && $request->user()->role !== Role::Owner, 403);
        $data = $request->validate([...$this->memberRules($request), 'is_active' => ['required', 'boolean']]);
        $target->role = Role::from($data['role']);
        $target->department_id = $data['department_id'] ?? null;
        $target->is_active = $data['is_active'];
        $target->save();

        return back()->with('success', 'Доступ сотрудника обновлён.');
    }

    private function memberRules(Request $request): array
    {
        $roles = [Role::Supervisor->value, Role::Operator->value, Role::Analyst->value];
        if ($request->user()->role === Role::Owner) {
            $roles[] = Role::Admin->value;
        }

        return [
            'role' => ['required', Rule::in($roles)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('organization_id', app(TenantContext::class)->id())],
        ];
    }
}

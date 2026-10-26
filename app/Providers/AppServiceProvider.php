<?php

namespace App\Providers;

use App\Models\Department;
use App\Models\Showroom;
use App\Models\Site;
use App\Policies\DirectoryPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);
    }

    public function boot(): void
    {
        foreach (['organization.manage', 'directory.manage', 'team.manage'] as $permission) {
            Gate::define($permission, fn ($user) => $user->hasPermission($permission));
        }
        foreach ([Showroom::class, Site::class, Department::class] as $model) {
            Gate::policy($model, DirectoryPolicy::class);
        }
    }
}

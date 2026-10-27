<?php

namespace App\Providers;

use App\Models\Conversation;
use App\Models\Department;
use App\Models\Showroom;
use App\Models\Site;
use App\Policies\ConversationPolicy;
use App\Policies\DirectoryPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);
    }

    public function boot(): void
    {
        Gate::policy(Conversation::class, ConversationPolicy::class);
        foreach (['chat.view', 'chat.reply', 'chat.manage'] as $permission) {
            Gate::define($permission, fn ($user) => $user->hasPermission($permission));
        }
        RateLimiter::for('login', function ($request) {
            $email = $request->input('email');
            $account = is_string($email) ? Str::lower(trim($email)) : '';

            return [
                Limit::perMinute(60)->by('ip:'.$request->ip()),
                Limit::perMinute(20)->by('account:'.$request->ip().'|'.hash('sha256', $account)),
            ];
        });
        RateLimiter::for('operator-api', fn ($request) => Limit::perMinute($request->isMethod('GET') ? 600 : 120)->by(($request->user()?->id ?? $request->ip()).'|'.($request->isMethod('GET') ? 'read' : 'write')));
        RateLimiter::for('widget-sessions', fn ($request) => Limit::perMinute(20)->by($request->ip().'|'.$request->route('channelPublicId')));
        RateLimiter::for('widget-identify', fn ($request) => Limit::perMinute(10)->by($request->attributes->get('visitor_session')->id));
        RateLimiter::for('widget-messages', fn ($request) => Limit::perMinute(60)->by($request->attributes->get('visitor_session')->id));
        RateLimiter::for('chat-uploads', fn ($request) => Limit::perMinute(10)->by($request->attributes->get('visitor_session')?->id ?? ('operator:'.$request->user()?->id)));

        foreach (['organization.manage', 'directory.manage', 'team.manage'] as $permission) {
            Gate::define($permission, fn ($user) => $user->hasPermission($permission));
        }
        foreach ([Showroom::class, Site::class, Department::class] as $model) {
            Gate::policy($model, DirectoryPolicy::class);
        }
    }
}

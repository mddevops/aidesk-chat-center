<?php

namespace App\Http\Controllers\Widgets;

use App\Chat\ChatException;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Department;
use App\Models\Site;
use App\Models\Widget;
use App\Models\WidgetVersion;
use App\Widgets\WidgetConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class WidgetController extends Controller
{
    private function authorizeManagement(Request $request): void
    {
        Gate::authorize('create', Widget::class);
    }

    public function index(Request $request)
    {
        $this->authorizeManagement($request);

        return Inertia::render('Widgets/Index', ['sites' => Site::orderBy('name')->get(['id', 'name', 'origin']), 'widgets' => Widget::get(), 'departments' => Department::orderBy('name')->get(['id', 'name']), 'defaults' => WidgetConfiguration::defaults()]);
    }

    public function create(Request $request, Site $site)
    {
        $this->authorizeManagement($request);
        $widget = DB::transaction(function () use ($site) {
            Site::whereKey($site->id)->lockForUpdate()->firstOrFail();
            $channel = Channel::firstOrCreate(['site_id' => $site->id, 'kind' => 'website'], ['name' => $site->name]);

            return Widget::firstOrCreate(['site_id' => $site->id], ['channel_id' => $channel->id, 'draft' => WidgetConfiguration::defaults()]);
        }, 3);

        return response()->json(['data' => $this->data($widget->fresh())]);
    }

    public function show(Request $request, Widget $widget)
    {
        Gate::authorize('update', $widget);

        return response()->json(['data' => $this->data($widget->fresh())]);
    }

    private function data(Widget $widget): array
    {
        return [...$widget->toArray(), 'channel_public_id' => Channel::findOrFail($widget->channel_id)->public_id, 'versions' => WidgetVersion::where('widget_id', $widget->id)->orderByDesc('version')->limit(100)->get(['version', 'configuration', 'created_at'])];
    }

    public function save(Request $request, Widget $widget)
    {
        Gate::authorize('update', $widget);
        $request->validate(['revision' => ['required', 'integer', 'min:1'], 'configuration' => ['required', 'array']]);
        $configuration = WidgetConfiguration::validate($request->input('configuration'));
        DB::transaction(function () use ($widget, $request, $configuration) {
            $locked = Widget::whereKey($widget->id)->lockForUpdate()->firstOrFail();
            $this->checkRevision($locked, $request);
            $locked->update(['draft' => $configuration, 'revision' => $locked->revision + 1]);
        }, 3);

        return response()->json(['data' => $this->data($widget->fresh())]);
    }

    public function publish(Request $request, Widget $widget)
    {
        Gate::authorize('update', $widget);
        $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($widget, $request) {
            $locked = Widget::whereKey($widget->id)->lockForUpdate()->firstOrFail();
            $this->checkRevision($locked, $request);
            $configuration = WidgetConfiguration::validate($locked->draft);
            $version = ($locked->published_version ?? 0) + 1;
            WidgetVersion::create(['widget_id' => $locked->id, 'published_by' => $request->user()->id, 'version' => $version, 'configuration' => $configuration]);
            $locked->update(['published_version' => $version, 'revision' => $locked->revision + 1]);
        }, 3);

        return response()->json(['data' => $this->data($widget->fresh())]);
    }

    private function checkRevision(Widget $widget, Request $request): void
    {
        if ($widget->revision !== (int) $request->input('revision')) {
            throw new ChatException('version_conflict', 'Настройки изменились. Загрузите актуальную версию перед сохранением.', 409);
        }
    }
}

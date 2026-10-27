<?php

namespace App\Http\Controllers\Widgets;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Site;
use App\Models\Widget;
use App\Models\WidgetVersion;
use Illuminate\Http\Request;

class PublicWidgetController extends Controller
{
    public function configuration(Request $request)
    {
        [$widget, $version] = $this->published($request);

        return response()->json(['data' => ['configuration' => $this->resolvedConfiguration($version), 'version' => $version->version, 'channel_public_id' => $request->attributes->get('widget_channel')->public_id]]);
    }

    private function published(Request $request): array
    {
        $widget = Widget::where('channel_id', $request->attributes->get('widget_channel')->id)->whereNotNull('published_version')->firstOrFail();

        return [$widget, WidgetVersion::where('widget_id', $widget->id)->where('version', $widget->published_version)->firstOrFail()];
    }

    private function resolvedConfiguration(WidgetVersion $version): array
    {
        return [...$version->configuration, 'departments' => Department::whereIn('id', $version->configuration['department_ids'] ?? [])->orderBy('name')->get(['id', 'name'])->toArray()];
    }

    public function frame(Request $request)
    {
        [$widget, $version] = $this->published($request);
        $origin = Site::findOrFail($widget->site_id)->origin;

        return response()->view('widget', ['configuration' => $this->resolvedConfiguration($version), 'channel' => $request->attributes->get('widget_channel')->public_id, 'parentOrigin' => $origin])
            ->header('Content-Security-Policy', "frame-ancestors {$origin}; object-src 'none'; base-uri 'none'")
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}

<?php

namespace App\Widgets;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class WidgetConfiguration
{
    public static function defaults(): array
    {
        return ['department_ids' => [], 'name' => 'Помощник автосалона', 'logo_url' => null, 'color' => '#7c5cfc', 'theme' => 'light', 'font' => 'system', 'width' => 380, 'height' => 600, 'position' => 'right', 'offset_x' => 24, 'offset_y' => 24, 'radius' => 20, 'shadow' => true, 'animation' => true, 'avatars' => true, 'greeting' => 'Здравствуйте! Поможем выбрать автомобиль и ответим на ваши вопросы.', 'buttons' => ['Подобрать автомобиль', 'Записаться на тест-драйв'], 'mobile_fullscreen' => true, 'mode' => 'online', 'offline_message' => 'Сейчас мы не в сети. Оставьте сообщение — ответим в рабочее время.', 'consent_text' => 'Даю согласие на обработку имени и телефона для ответа на обращение.', 'privacy_url' => null];
    }

    public static function validate(array $input): array
    {
        $result = Validator::make($input, [
            'department_ids' => ['sometimes', 'array', 'max:30'],
            'department_ids.*' => ['integer', 'distinct', Rule::exists('departments', 'id')->where('organization_id', app(TenantContext::class)->id())],
            'name' => ['required', 'string', 'max:100'],
            'logo_url' => ['nullable', 'url:https', 'max:2048'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme' => ['required', Rule::in(['light', 'dark', 'auto'])],
            'font' => ['required', Rule::in(['system', 'sans', 'serif'])],
            'width' => ['required', 'integer', 'between:300,520'],
            'height' => ['required', 'integer', 'between:400,800'],
            'position' => ['required', Rule::in(['left', 'right'])],
            'offset_x' => ['required', 'integer', 'between:0,80'],
            'offset_y' => ['required', 'integer', 'between:0,80'],
            'radius' => ['required', 'integer', 'between:0,32'],
            'shadow' => ['required', 'boolean'], 'animation' => ['required', 'boolean'], 'avatars' => ['required', 'boolean'],
            'greeting' => ['required', 'string', 'max:1000'],
            'buttons' => ['present', 'array', 'max:4'], 'buttons.*' => ['required', 'string', 'max:100'],
            'mobile_fullscreen' => ['required', 'boolean'],
            'mode' => ['required', Rule::in(['online', 'offline'])],
            'offline_message' => ['required', 'string', 'max:1000'],
            'consent_text' => ['required', 'string', 'max:2000'],
            'privacy_url' => ['nullable', 'url:https', 'max:2048'],
        ])->validate();
        $result['department_ids'] = array_map('intval', $result['department_ids'] ?? []);

        return $result;
    }
}

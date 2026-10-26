<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Supervisor = 'supervisor';
    case Operator = 'operator';
    case Analyst = 'analyst';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Владелец', self::Admin => 'Администратор',
            self::Supervisor => 'Руководитель', self::Operator => 'Оператор', self::Analyst => 'Аналитик',
        };
    }

    public function permissions(): array
    {
        return match ($this) {
            self::Owner, self::Admin => ['organization.manage', 'team.manage', 'directory.manage', 'chat.manage', 'chat.view', 'chat.reply', 'reports.view'],
            self::Supervisor => ['chat.manage', 'chat.view', 'chat.reply', 'reports.view'],
            self::Operator => ['chat.view', 'chat.reply'],
            self::Analyst => ['reports.view'],
        };
    }
}

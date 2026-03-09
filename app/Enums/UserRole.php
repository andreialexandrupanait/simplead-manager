<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Manager => 'Manager',
            self::Viewer => 'Viewer',
        };
    }

    public function canManageSites(): bool
    {
        return $this !== self::Viewer;
    }

    public function canAccessSettings(): bool
    {
        return $this === self::Admin;
    }

    public function canDeleteResources(): bool
    {
        return $this === self::Admin;
    }
}

<?php

namespace App\Enums;

enum TenantRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Supervisor = 'supervisor';
    case Agent = 'agent';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Supervisor => 'Supervisor',
            self::Agent => 'Agent',
        };
    }

    public function canManageTenant(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    public function canManageIntegrations(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    public function canManageSecurity(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    public function canViewAllCalls(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Supervisor], true);
    }

    public function canStreamRecordings(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Supervisor], true);
    }
}

<?php

namespace App\Modules\Tenancy\Enums;

enum TenantRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    public function rank(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Admin => 2,
            self::Member => 1,
        };
    }

    /** Owner or admin. */
    public function isAdmin(): bool
    {
        return $this->rank() >= self::Admin->rank();
    }
}

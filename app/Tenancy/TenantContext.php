<?php

namespace App\Tenancy;

use LogicException;

class TenantContext
{
    private ?int $id = null;

    public function id(): ?int
    {
        return $this->id;
    }

    public function set(int $id): void
    {
        if ($this->id !== null && $this->id !== $id) {
            throw new LogicException('Tenant context cannot change within one operation.');
        }
        $this->id = $id;
    }

    public function clear(): void
    {
        $this->id = null;
    }
}

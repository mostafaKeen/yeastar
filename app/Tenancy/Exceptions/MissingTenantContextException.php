<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

class MissingTenantContextException extends RuntimeException
{
    public static function forModel(string $modelClass): self
    {
        return new self(
            sprintf(
                'Refused to query or mutate tenant-scoped model [%s] without an active TenantContext. Bind a tenant or wrap explicitly in TenantContext::withoutTenancy().',
                $modelClass,
            ),
        );
    }
}

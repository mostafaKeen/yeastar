<?php

namespace App\Tenancy\Scopes;

use App\Tenancy\Exceptions\MissingTenantContextException;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        $tenantId = $context->id();

        if ($tenantId === null) {
            throw MissingTenantContextException::forModel($model::class);
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}

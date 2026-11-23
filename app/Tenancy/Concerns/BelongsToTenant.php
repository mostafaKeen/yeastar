<?php

namespace App\Tenancy\Concerns;

use App\Models\Tenant;
use App\Tenancy\Exceptions\MissingTenantContextException;
use App\Tenancy\Scopes\TenantScope;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            /** @var TenantContext $context */
            $context = app(TenantContext::class);

            if ($context->isBypassed()) {
                return;
            }

            $tenantId = $context->id();

            if ($tenantId === null) {
                throw MissingTenantContextException::forModel($model::class);
            }

            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', $tenantId);

                return;
            }

            if ((string) $model->getAttribute('tenant_id') !== $tenantId) {
                throw new MissingTenantContextException(
                    sprintf('Cross-tenant write blocked on [%s]: payload tenant_id does not match active TenantContext.', $model::class),
                );
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}

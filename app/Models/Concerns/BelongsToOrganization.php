<?php

namespace App\Models\Concerns;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

trait BelongsToOrganization
{
    protected static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $query) {
            $id = app(TenantContext::class)->id();
            $id === null ? $query->whereRaw('1 = 0') : $query->where($query->qualifyColumn('organization_id'), $id);
        });
        static::saving(function ($model) {
            $id = app(TenantContext::class)->id();
            if ($id === null || ($model->organization_id !== null && (int) $model->organization_id !== $id)
                || ($model->exists && (int) $model->getOriginal('organization_id') !== $id)) {
                throw new LogicException('Organization mismatch or missing tenant context.');
            }
            $model->organization_id = $id;
        });
        static::deleting(function ($model) {
            if ((int) $model->organization_id !== app(TenantContext::class)->id()) {
                throw new LogicException('Organization mismatch.');
            }
        });
    }
}

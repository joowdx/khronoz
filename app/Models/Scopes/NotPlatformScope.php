<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class NotPlatformScope implements Scope
{
    /**
     * Hide the platform row from every ordinary query; Agency::platform() is
     * the only path that reaches it (docs/design/07-constraints.md).
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('platform'), false);
    }
}

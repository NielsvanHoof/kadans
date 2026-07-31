<?php

declare(strict_types=1);

namespace App\Tenancy\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use App\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Base model for records scoped to a workspace tenant.
 */
abstract class TenantOwnedModel extends Model
{
    use BelongsToTenant;

    /**
     * @return Builder<static>
     */
    final public static function queryWithoutTenantScope(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }
}

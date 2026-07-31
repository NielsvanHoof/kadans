<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Context;

/**
 * Tenant execution helpers and opt-in strict BelongsToTenant scoping.
 *
 * Strict mode is stored in Laravel Context so it stays request/job scoped
 * and does not leak across Octane workers.
 */
final class TenantContext
{
    private const string StrictContextKey = 'tenancy.strict_tenant_scope';

    public function current(): ?Tenant
    {
        return Tenant::current();
    }

    public function isStrict(): bool
    {
        return (bool) Context::getHidden(self::StrictContextKey, false);
    }

    public function enableStrict(): void
    {
        Context::addHidden(self::StrictContextKey, true);
    }

    public function disableStrict(): void
    {
        Context::forgetHidden(self::StrictContextKey);
    }

    /**
     * Run a callback with strict tenant scoping enabled, then restore the prior flag.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runStrict(callable $callback): mixed
    {
        $wasStrict = $this->isStrict();
        $this->enableStrict();

        try {
            return $callback();
        } finally {
            if ($wasStrict) {
                $this->enableStrict();
            } else {
                $this->disableStrict();
            }
        }
    }

    /**
     * Run a callback under the given tenant with strict scoping for the duration.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runForTenant(Tenant $tenant, callable $callback): mixed
    {
        return $tenant->execute(fn (): mixed => $this->runStrict($callback));
    }
}

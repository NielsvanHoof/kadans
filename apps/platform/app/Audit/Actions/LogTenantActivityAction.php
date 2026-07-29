<?php

declare(strict_types=1);

namespace App\Audit\Actions;

use App\Audit\Models\Activity;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Override;
use Spatie\Activitylog\Actions\LogActivityAction;

use function is_int;

final class LogTenantActivityAction extends LogActivityAction
{
    #[Override]
    protected function beforeActivityLogged(Model $activity): void
    {
        parent::beforeActivityLogged($activity);

        if ($activity->getAttribute('tenant_id') !== null) {
            return;
        }

        $tenantId = $this->resolveTenantId($activity);

        if ($tenantId !== null) {
            $activity->setAttribute('tenant_id', $tenantId);
        }
    }

    private function resolveTenantId(Model $activity): ?int
    {
        $currentTenant = Tenant::current();

        if ($currentTenant instanceof Tenant) {
            return $currentTenant->id;
        }

        if (! $activity instanceof Activity) {
            return null;
        }

        $subject = $activity->getRelationValue('subject');

        if ($subject instanceof Model) {
            $tenantId = $subject->getAttribute('tenant_id');

            if (is_int($tenantId)) {
                return $tenantId;
            }
        }

        return null;
    }
}

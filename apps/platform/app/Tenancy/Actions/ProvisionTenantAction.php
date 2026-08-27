<?php

declare(strict_types=1);

namespace App\Tenancy\Actions;

use App\Accounts\Models\User;
use App\Tenancy\Enums\Role as RoleEnum;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Support\StaffOptionsCache;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

use function getPermissionsTeamId;
use function setPermissionsTeamId;

final readonly class ProvisionTenantAction
{
    public function __construct(private SyncTenantRolesAction $syncTenantRoles) {}

    public function handle(
        string $name,
        User $owner,
        ?string $slug = null,
        RoleEnum $ownerRole = RoleEnum::Owner,
    ): Tenant {
        $previousPermissionsTeamId = getPermissionsTeamId();

        try {
            $tenant = DB::transaction(function () use ($name, $owner, $slug, $ownerRole): Tenant {
                $tenant = $this->createTenantWithAvailableSlug($name, $slug);

                $this->seedRolesForTenant($tenant);

                TenantMembership::query()->create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $owner->id,
                    'status' => TenantMembershipStatus::Active,
                    'joined_at' => now(),
                    'last_accessed_at' => now(),
                ]);

                setPermissionsTeamId($tenant->id);
                $owner->unsetRelation('roles');
                $owner->unsetRelation('permissions');
                $owner->assignRole($ownerRole->value);

                return $tenant;
            });

            StaffOptionsCache::forget($tenant->id);

            return $tenant;
        } catch (Throwable $exception) {
            setPermissionsTeamId($previousPermissionsTeamId);
            $owner->unsetRelation('roles');
            $owner->unsetRelation('permissions');

            throw $exception;
        }
    }

    private function createTenantWithAvailableSlug(string $name, ?string $requestedSlug): Tenant
    {
        $baseSlug = Str::slug($requestedSlug ?? $name);

        if ($baseSlug === '') {
            $baseSlug = 'workspace';
        }

        $suffix = 1;

        while (true) {
            $slug = $this->slugWithSuffix($baseSlug, $suffix);

            if (Tenant::query()->where('slug', $slug)->toBase()->exists()) {
                $suffix++;

                continue;
            }

            try {
                return DB::transaction(fn (): Tenant => Tenant::query()->create([
                    'name' => $name,
                    'slug' => $slug,
                ]));
            } catch (UniqueConstraintViolationException) {
                $suffix++;
            }
        }
    }

    private function slugWithSuffix(string $baseSlug, int $suffix): string
    {
        if ($suffix === 1) {
            return Str::limit($baseSlug, 255, '');
        }

        $suffixValue = "-$suffix";

        return Str::limit($baseSlug, 255 - mb_strlen($suffixValue), '').$suffixValue;
    }

    /**
     * Delegates to the shared sync path so a freshly provisioned firm and an
     * existing one always end up with identical role definitions.
     */
    private function seedRolesForTenant(Tenant $tenant): void
    {
        $this->syncTenantRoles->syncTenant($tenant);

        setPermissionsTeamId($tenant->id);
    }
}

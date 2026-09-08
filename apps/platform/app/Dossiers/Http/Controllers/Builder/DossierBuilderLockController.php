<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers\Builder;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Dossiers\Actions\Builder\AcquireDossierBuilderLockAction;
use App\Dossiers\Actions\Builder\HeartbeatDossierBuilderLockAction;
use App\Dossiers\Actions\Builder\ReleaseDossierBuilderLockAction;
use App\Dossiers\Http\Requests\Builder\AcquireDossierBuilderLockRequest;
use App\Dossiers\Http\Requests\Builder\HeartbeatDossierBuilderLockRequest;
use App\Dossiers\Http\Requests\Builder\ReleaseDossierBuilderLockRequest;
use App\Dossiers\Models\Dossier;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * Exclusive questionnaire-builder edit lease endpoints.
 */
final class DossierBuilderLockController extends Controller
{
    use ResolvesCurrentUser;

    /**
     * Claim the lock when free, or report who currently holds it.
     */
    public function store(
        Tenant $tenant,
        AcquireDossierBuilderLockRequest $request,
        Dossier $dossier,
        AcquireDossierBuilderLockAction $acquireDossierBuilderLock,
    ): JsonResponse {
        $user = $this->currentUser();

        $lock = $acquireDossierBuilderLock->handle($dossier, $user);

        return response()->json([
            'builder_lock' => $lock->toArray(),
        ]);
    }

    /**
     * Extend the lock TTL for the holding tab.
     */
    public function heartbeat(
        Tenant $tenant,
        HeartbeatDossierBuilderLockRequest $request,
        Dossier $dossier,
        HeartbeatDossierBuilderLockAction $heartbeatDossierBuilderLock,
    ): JsonResponse {
        $user = $this->currentUser();

        $lock = $heartbeatDossierBuilderLock->handle(
            $dossier,
            $user,
            (string) $request->validated('token'),
        );

        return response()->json([
            'builder_lock' => $lock->toArray(),
        ]);
    }

    /**
     * Release the lock when navigating away or closing the tab.
     */
    public function destroy(
        Tenant $tenant,
        ReleaseDossierBuilderLockRequest $request,
        Dossier $dossier,
        ReleaseDossierBuilderLockAction $releaseDossierBuilderLock,
    ): JsonResponse {
        $user = $this->currentUser();

        $lock = $releaseDossierBuilderLock->handle(
            $dossier,
            $user,
            (string) $request->validated('token'),
        );

        return response()->json([
            'builder_lock' => $lock->toArray(),
        ]);
    }
}

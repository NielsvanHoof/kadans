<?php

declare(strict_types=1);

namespace App\Dossiers\Actions;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Events\DossierArchived;
use App\Dossiers\Models\Dossier;
use Illuminate\Support\Facades\DB;

final readonly class DeleteDossierAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
    ) {}

    public function handle(Dossier $dossier, User $deletedBy): void
    {
        DB::transaction(function () use ($dossier, $deletedBy): void {
            $dossierQuery = Dossier::query()->whereKey($dossier->getKey());
            $dossierQuery->getQuery()->lockForUpdate();
            $lockedDossier = $dossierQuery->firstOrFail();

            if (! $lockedDossier->trashed()) {
                // ClientPortal listens and revokes live magic-link grants.
                event(new DossierArchived($lockedDossier, $deletedBy));

                $this->logAuditActivity->handle(
                    AuditEvent::DossierDeleted,
                    $lockedDossier,
                    [
                        'title' => $lockedDossier->title,
                        'reference' => $lockedDossier->reference,
                        'status' => $lockedDossier->status->value,
                    ],
                    $deletedBy,
                );

                $lockedDossier->disableLogging();
                $lockedDossier->forceFill(['next_reminder_at' => null])->save();
                $lockedDossier->deleteOrFail();
            }
        });
    }
}

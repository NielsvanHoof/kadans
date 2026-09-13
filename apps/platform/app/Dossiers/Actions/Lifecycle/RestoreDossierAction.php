<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\Lifecycle;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Support\DossierLocks;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class RestoreDossierAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private DossierLocks $dossierLocks,
    ) {}

    public function handle(Dossier $dossier, User $restoredBy): void
    {
        DB::transaction(function () use ($dossier, $restoredBy): void {
            $lockedDossier = $this->dossierLocks->trashedDossier($dossier);

            if (! $lockedDossier->trashed()) {
                return;
            }

            $client = Client::withTrashed()->findOrFail($lockedDossier->client_id);

            if ($client->trashed()) {
                throw ValidationException::withMessages([
                    'dossier' => __('Restore the client before restoring this dossier.'),
                ]);
            }

            $lockedDossier->disableLogging();
            $lockedDossier->forceFill(['deleted_at' => null])->saveOrFail();

            $this->logAuditActivity->handle(
                AuditEvent::DossierRestored,
                $lockedDossier,
                [
                    'title' => $lockedDossier->title,
                    'reference' => $lockedDossier->reference,
                    'status' => $lockedDossier->status->value,
                ],
                $restoredBy,
            );
        });
    }
}

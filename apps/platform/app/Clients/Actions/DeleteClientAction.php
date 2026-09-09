<?php

declare(strict_types=1);

namespace App\Clients\Actions;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Clients\Models\Client;
use App\Dossiers\Actions\Lifecycle\DeleteDossierAction;
use App\Dossiers\Models\Dossier;
use Illuminate\Support\Facades\DB;

final readonly class DeleteClientAction
{
    public function __construct(
        private DeleteDossierAction $deleteDossier,
        private LogAuditActivityAction $logAuditActivity,
    ) {}

    public function handle(Client $client, User $deletedBy): void
    {
        DB::transaction(function () use ($client, $deletedBy): void {
            $clientQuery = Client::query()->whereKey($client->getKey());
            $clientQuery->getQuery()->lockForUpdate();
            $lockedClient = $clientQuery->firstOrFail();

            if (! $lockedClient->trashed()) {
                Dossier::query()
                    ->where('client_id', $lockedClient->id)
                    ->get()
                    ->each(function (Dossier $dossier) use ($deletedBy): void {
                        $this->deleteDossier->handle($dossier, $deletedBy);
                    });

                $this->logAuditActivity->handle(
                    AuditEvent::ClientDeleted,
                    $lockedClient,
                    [
                        'name' => $lockedClient->name,
                        'email' => $lockedClient->email,
                    ],
                    $deletedBy,
                );

                $lockedClient->disableLogging();
                $lockedClient->deleteOrFail();
            }
        });
    }
}

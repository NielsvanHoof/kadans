<?php

declare(strict_types=1);

namespace App\ClientPortal\Actions;

use App\ClientPortal\Notifications\ClientChangesRequestedNotification;
use App\Clients\Models\Client;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

final class SendClientChangesRequestedNotificationAction
{
    public function handle(DocumentRequest $documentRequest): void
    {
        $documentRequest->loadMissing([
            'dossier.client',
            'dossier.tenant',
        ]);

        $dossier = $documentRequest->dossier;

        throw_unless($dossier instanceof Dossier, RuntimeException::class, 'Document request dossier is missing.');

        $client = $dossier->client;

        throw_unless($client instanceof Client, RuntimeException::class, 'Dossier client is missing.');

        $firmName = $dossier->tenant instanceof Tenant
            ? $dossier->tenant->name
            : Config::string('app.name');

        Notification::route('mail', $client->email)
            ->notify(
                new ClientChangesRequestedNotification(
                    documentRequestId: $documentRequest->id,
                    dossierId: $dossier->id,
                    clientName: $client->name,
                    dossierTitle: $dossier->title,
                    documentRequestTitle: $documentRequest->title,
                    firmName: $firmName,
                )->locale(app()->getLocale()),
            );
    }
}

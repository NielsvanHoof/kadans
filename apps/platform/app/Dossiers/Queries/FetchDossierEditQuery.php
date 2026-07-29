<?php

declare(strict_types=1);

namespace App\Dossiers\Queries;

use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Shared\Data\PersonOptionData;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Queries\FetchResponsibleStaffOptionsQuery;
use RuntimeException;

/**
 * Shape dossier edit-form props for Inertia.
 */
final readonly class FetchDossierEditQuery
{
    public function __construct(
        private FetchResponsibleStaffOptionsQuery $fetchResponsibleStaffOptions,
    ) {}

    /**
     * @return array{
     *     dossier: array{
     *         id: int,
     *         title: string,
     *         reference: string|null,
     *         due_date: string|null,
     *         responsible_user_id: int|null,
     *         reminder_interval_days: int|null,
     *         client: array{name: string, email: string}
     *     },
     *     responsible_staff: list<array{id: int, name: string, email: string}>
     * }
     */
    public function handle(Tenant $tenant, Dossier $dossier): array
    {
        $dossier->load('client:id,name,email');
        $client = $dossier->client;

        throw_unless($client instanceof Client, RuntimeException::class, 'Dossier client is missing.');

        return [
            'dossier' => [
                'id' => $dossier->id,
                'title' => $dossier->title,
                'reference' => $dossier->reference,
                'due_date' => $dossier->due_date?->toDateString(),
                'responsible_user_id' => $dossier->responsible_user_id,
                'reminder_interval_days' => $dossier->reminder_interval_days,
                'client' => [
                    'name' => $client->name,
                    'email' => $client->email,
                ],
            ],
            'responsible_staff' => array_map(
                static fn (PersonOptionData $staff): array => $staff->toArray(),
                $this->fetchResponsibleStaffOptions->handle($tenant),
            ),
        ];
    }
}

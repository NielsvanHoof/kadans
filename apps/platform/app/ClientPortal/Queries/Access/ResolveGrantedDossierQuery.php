<?php

declare(strict_types=1);

namespace App\ClientPortal\Queries\Access;

use App\ClientPortal\Models\ClientAccessGrant;
use App\Dossiers\Models\Dossier;
use Symfony\Component\HttpFoundation\Response;

/**
 * The dossier this request is about, or a 404.
 *
 * Every portal adapter that used to compare `$documentRequest->dossier_id`
 * against `$grant->dossier_id` calls this instead. Centralising it is the point:
 * the comparison lived in seven places, and a client-scoped grant turns a
 * one-line equality into a query that must not be re-derived per caller.
 *
 * Takes an id rather than a Dossier so a caller cannot resolve the model through
 * an unscoped query and hand it over already trusted. The only way to get a
 * Dossier out of here is to have proven the grant reaches it.
 *
 * Aborts 404, never 403: portal denials are concealed, and deliberately do not
 * reach LogAccessDeniedAction, or cross-dossier probing would flood the audit
 * trail. See the ClientPortal rules.
 *
 * Says nothing about whether the client may *write* to what it returns. That is
 * DocumentRequestTransitions::canSubmit(), which is its own chokepoint — a
 * completed dossier stays readable and refuses submissions.
 */
final readonly class ResolveGrantedDossierQuery
{
    public function __construct(private FetchReachableDossiersQuery $fetchReachableDossiers) {}

    public function handle(ClientAccessGrant $grant, ?int $dossierId): Dossier
    {
        abort_if($dossierId === null, Response::HTTP_NOT_FOUND);

        $dossier = $this->fetchReachableDossiers
            ->handle($grant, onlyDossierId: $dossierId)
            ->first();

        abort_unless($dossier instanceof Dossier, Response::HTTP_NOT_FOUND);

        return $dossier;
    }
}

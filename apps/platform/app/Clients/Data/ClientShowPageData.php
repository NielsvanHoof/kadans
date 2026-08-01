<?php

declare(strict_types=1);

namespace App\Clients\Data;

use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Full payload for the client show Inertia page.
 */
#[TypeScript]
final class ClientShowPageData extends Data
{
    /**
     * Row shape is owned by ClientDossierRowData; see its generated TypeScript.
     *
     * @param  LengthAwarePaginator<int, array<string, mixed>>  $dossiers
     */
    public function __construct(
        public readonly ClientSummaryData $client,
        public readonly LengthAwarePaginator $dossiers,
    ) {}
}

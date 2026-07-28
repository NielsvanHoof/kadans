<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\Builder;

use App\Dossiers\Models\DocumentRequest;

/**
 * Update a questionnaire item's editable attributes on the form builder.
 */
final class UpdateDocumentRequestAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(DocumentRequest $documentRequest, array $attributes): void
    {
        $documentRequest->update($attributes);
    }
}

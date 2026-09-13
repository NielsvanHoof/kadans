<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Ai\Search\Tools;

use App\Dossiers\Data\UploadedDocuments\UploadedDocumentSearchHitData;
use App\Dossiers\Queries\UploadedDocuments\SearchUploadedDocumentsQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

use function json_encode;

/**
 * Keyword + hybrid Scout search over this tenant's uploaded documents.
 *
 * Returns the same safe metadata as the staff palette — never raw OCR fields.
 */
final class SearchUploadedDocuments implements Tool
{
    public function description(): string
    {
        return 'Search this firm\'s uploaded documents. The query is matched as one substring against filename and OCR text — pass a short token (client name, IBAN, leasecontract, loonstrook), not a full sentence. Returns id, filename, type, summary, client, and dossier. Does not return extracted field values.';
    }

    public function handle(Request $request): string
    {
        $query = mb_trim((string) $request['query']);

        if ($query === '') {
            return '[]';
        }

        $result = resolve(SearchUploadedDocumentsQuery::class)->handle($query);

        $payload = array_map(
            fn (UploadedDocumentSearchHitData $hit): array => [
                'id' => $hit->id,
                'original_filename' => $hit->originalFilename,
                'document_type' => $hit->documentType,
                'summary' => $hit->summary,
                'request_title' => $hit->requestTitle,
                'dossier_id' => $hit->dossierId,
                'dossier_title' => $hit->dossierTitle,
                'client_name' => $hit->clientName,
            ],
            $result['hits'],
        );

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->min(1)->required(),
        ];
    }
}

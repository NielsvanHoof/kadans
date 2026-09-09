<?php

declare(strict_types=1);

namespace App\Reporting\Actions\DocumentSearch;

use App\DocumentProcessing\Ai\Search\DocumentSearchAgent;
use App\Dossiers\Models\UploadedDocument;
use App\Reporting\Data\DocumentSearch\DocumentSearchAskResultData;
use App\Reporting\Data\DocumentSearch\DocumentSearchCitationData;
use App\Tenancy\Enums\TenantAiFeature;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Queries\Settings\ResolveTenantSettingsQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Laravel\Ai\Responses\StructuredAgentResponse;

use function array_values;
use function is_array;
use function is_int;
use function is_string;

final readonly class AskDocumentSearchAction
{
    public function __construct(private ResolveTenantSettingsQuery $resolveTenantSettings) {}

    public function handle(string $question, string $languageLabel): DocumentSearchAskResultData
    {
        if (! $this->resolveTenantSettings->isAiFeatureEnabled(TenantAiFeature::DocumentSearch)) {
            throw new AuthorizationException(__('AI document search is disabled for this firm.'));
        }

        $agent = new DocumentSearchAgent($languageLabel);
        $response = $agent->prompt($question);
        $structured = $response instanceof StructuredAgentResponse ? $response->toArray() : [];

        $answer = is_string($structured['answer'] ?? null) ? $structured['answer'] : '';
        $rawCitations = is_array($structured['citations'] ?? null) ? $structured['citations'] : [];

        if ($answer === '') {
            Log::warning('Document search agent returned an empty answer.');
        }

        return new DocumentSearchAskResultData(
            answer: $answer === '' ? __('No matching documents were found.') : $answer,
            citations: $this->hydrateCitations(array_values($rawCitations)),
        );
    }

    /**
     * @param  list<mixed>  $rawCitations
     * @return list<DocumentSearchCitationData>
     */
    private function hydrateCitations(array $rawCitations): array
    {
        $tenant = Tenant::current();

        if (! $tenant instanceof Tenant) {
            return [];
        }

        $ids = [];
        $reasons = [];

        foreach ($rawCitations as $citation) {
            if (! is_array($citation)) {
                continue;
            }

            $id = $citation['uploaded_document_id'] ?? null;
            $why = $citation['why'] ?? '';

            if (! is_int($id) || $id < 1) {
                continue;
            }

            $ids[] = $id;
            $reasons[$id] = is_string($why) ? $why : '';
        }

        if ($ids === []) {
            return [];
        }

        $documents = UploadedDocument::query()
            ->whereKey($ids)
            ->get()
            ->keyBy('id');

        $hydrated = [];

        foreach ($ids as $id) {
            $document = $documents->get($id);

            if (! $document instanceof UploadedDocument) {
                continue;
            }

            $hydrated[] = new DocumentSearchCitationData(
                uploadedDocumentId: $document->id,
                why: $reasons[$id] ?? '',
                originalFilename: $document->original_filename,
                showUrl: URL::route('workspaces.uploaded-documents.show', [
                    'tenant' => $tenant,
                    'uploadedDocument' => $document,
                ]),
            );
        }

        return $hydrated;
    }
}

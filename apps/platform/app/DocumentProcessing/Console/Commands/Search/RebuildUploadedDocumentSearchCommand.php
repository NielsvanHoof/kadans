<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Console\Commands\Search;

use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Services\Ocr\FlattenExtractedTextForSearch;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;
use function sprintf;

#[Signature('documents:rebuild-searchable-text {--limit=200 : Documents to reindex per tenant batch}')]
#[Description('Rebuild searchable_text and embeddings for completed OCR documents')]
final class RebuildUploadedDocumentSearchCommand extends Command
{
    public function handle(
        TenantContext $tenantContext,
        FlattenExtractedTextForSearch $flatten,
    ): int {
        $limit = max(1, (int) $this->option('limit'));
        $reindexed = 0;

        Tenant::query()->each(function (Tenant $tenant) use ($tenantContext, $flatten, $limit, &$reindexed): void {
            $tenantContext->runForTenant($tenant, function () use ($flatten, $limit, &$reindexed): void {
                $documentsQuery = UploadedDocument::query()
                    ->where('processing_status', DocumentProcessingStatus::Completed);

                // Prefer the base query builder for whereNotNull/orderBy to satisfy strict-rules.
                $documentsQuery->getQuery()
                    ->whereNotNull('extracted_text')
                    ->orderBy('id');

                $documentsQuery->chunkById($limit, function ($documents) use ($flatten, &$reindexed): void {
                    foreach ($documents as $document) {
                        $document->forceFill([
                            'searchable_text' => $flatten->searchableText(
                                $document->original_filename,
                                $document->extracted_text,
                            ),
                        ])->saveQuietly();

                        $document->searchable();
                        $reindexed++;
                    }
                });
            });
        });

        info(sprintf('Reindexed %d uploaded document(s).', $reindexed));

        return self::SUCCESS;
    }
}

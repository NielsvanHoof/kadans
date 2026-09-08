<?php

declare(strict_types=1);

namespace App\Reporting\Http\Controllers\DocumentSearch;

use App\Accounts\Enums\Locale;
use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Queries\UploadedDocuments\SearchUploadedDocumentsQuery;
use App\Reporting\Actions\DocumentSearch\AskDocumentSearchAction;
use App\Reporting\Data\DocumentSearch\DocumentSearchResultsData;
use App\Reporting\Http\Requests\DocumentSearch\StoreDocumentSearchAskRequest;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DocumentSearchController extends Controller
{
    use ResolvesCurrentUser;

    public function index(
        Tenant $tenant,
        Request $request,
        SearchUploadedDocumentsQuery $searchUploadedDocuments,
    ): JsonResponse {
        $this->authorize('viewAny', DocumentRequest::class);

        $term = $request->string('q')->trim()->toString();
        $result = $searchUploadedDocuments->handle($term);

        return response()->json(new DocumentSearchResultsData(
            hits: $result['hits'],
            truncated: $result['truncated'],
        )->toArray());
    }

    public function store(
        Tenant $tenant,
        StoreDocumentSearchAskRequest $request,
        AskDocumentSearchAction $askDocumentSearch,
    ): JsonResponse {
        $this->authorize('viewAny', DocumentRequest::class);

        $languageLabel = (Locale::tryFrom($this->currentUser()->preferredLocale()) ?? Locale::English)->label();

        $result = $askDocumentSearch->handle(
            $request->validated('question'),
            $languageLabel,
        );

        return response()->json($result->toArray());
    }
}

<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers\Reminders;

use App\Accounts\Enums\Locale;
use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Dossiers\Actions\DocumentRequests\ResolveApplicableDocumentRequestsAction;
use App\Dossiers\Actions\Reminders\DraftDossierReminderMessageAction;
use App\Dossiers\Data\Reminders\ReminderDraftContextData;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Queries\Lifecycle\DossierHasOutstandingClientItemsQuery;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class DossierReminderDraftController extends Controller
{
    use ResolvesCurrentUser;

    public function store(
        Tenant $tenant,
        Dossier $dossier,
        DossierHasOutstandingClientItemsQuery $dossierHasOutstandingClientItems,
        ResolveApplicableDocumentRequestsAction $resolveApplicableDocumentRequests,
        DraftDossierReminderMessageAction $draftDossierReminderMessage,
    ): JsonResponse {
        // Also covers Completed dossiers — the policy denies those outright.
        $this->authorize('sendReminder', $dossier);

        if (! $dossierHasOutstandingClientItems->handle($dossier)) {
            throw ValidationException::withMessages([
                'dossier' => __('This dossier has no items awaiting the client.'),
            ]);
        }

        $dossier->loadMissing('client');

        $documentRequests = DocumentRequest::query()
            ->whereBelongsTo($dossier)
            ->oldest('sort_order')
            ->oldest('id')
            ->get();

        /** @var list<string> $outstandingItemTitles */
        $outstandingItemTitles = $resolveApplicableDocumentRequests
            ->handle($documentRequests)
            ->filter(fn (DocumentRequest $documentRequest): bool => $documentRequest->status->isAwaitingClient())
            ->map(fn (DocumentRequest $documentRequest): string => $documentRequest->title)
            ->values()
            ->all();

        $languageLabel = (Locale::tryFrom($this->currentUser()->preferredLocale()) ?? Locale::English)->label();

        $message = $draftDossierReminderMessage->handle(
            new ReminderDraftContextData(
                dossierTitle: $dossier->title,
                clientName: $dossier->client->name ?? '',
                dueDate: $dossier->due_date?->format('j F Y'),
                outstandingItemTitles: $outstandingItemTitles,
            ),
            $languageLabel,
        );

        return response()->json(['message' => $message]);
    }
}

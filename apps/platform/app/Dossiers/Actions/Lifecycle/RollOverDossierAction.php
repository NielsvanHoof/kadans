<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\Lifecycle;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Data\Lifecycle\RollOverDossierData;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Support\DossierLocks;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function array_key_exists;
use function is_string;

/**
 * Start the next period from an existing dossier.
 *
 * Clones the questionnaire structure and, unless staff opt out, the answers
 * that are still true a year later. Deliberately narrow about what travels:
 *
 * - Questions, instructions, configuration and order: copied.
 * - Answers: copied per type, and only for types that survive a period
 *   (QuestionnaireItemType::carriesAnswerAcrossPeriods()).
 * - Uploaded files, pipelines, audio recordings, portal access grants,
 *   exports, review verdicts and the builder lock: never copied. Those all
 *   describe the period they were created in.
 *
 * A carried answer is left Pending and stamped with carried_forward_at, so it
 * reads as last period's value awaiting confirmation rather than an answer the
 * client has given for this one. That keeps DocumentRequestStatus unchanged,
 * so every exhaustive match on it — isAwaitingClient(), the dashboard
 * follow-up queries, portal progress — keeps working untouched.
 */
final readonly class RollOverDossierAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private DossierLocks $dossierLocks,
    ) {}

    public function handle(Dossier $source, RollOverDossierData $data): Dossier
    {
        return DB::transaction(function () use ($source, $data): Dossier {
            $lockedSource = $this->dossierLocks->dossier($source);
            $sourceDocumentRequests = $this->dossierLocks->documentRequestsFor($lockedSource);

            $periodYear = $data->periodYear ?? $this->nextPeriodYear($lockedSource);

            $rolledOver = Dossier::query()->create([
                'client_id' => $lockedSource->client_id,
                'title' => $data->title,
                'reference' => $data->reference,
                'period_year' => $periodYear,
                'period_label' => $data->periodLabel ?? $this->nextPeriodLabel($lockedSource, $periodYear),
                'rolled_from_dossier_id' => $lockedSource->getKey(),
                'status' => DossierStatus::Draft,
                'responsible_user_id' => $lockedSource->responsible_user_id,
                'due_date' => $data->dueDate,
                'reminder_interval_days' => $lockedSource->reminder_interval_days,
            ]);

            $this->cloneDocumentRequests($sourceDocumentRequests, $rolledOver, $data->carryAnswers);

            $this->logAuditActivity->handle(
                AuditEvent::DossierRolledOver,
                $rolledOver,
                [
                    'rolled_from_dossier_id' => $lockedSource->getKey(),
                    'document_requests' => $sourceDocumentRequests->count(),
                    'answers_carried' => $data->carryAnswers,
                ],
            );

            return $rolledOver;
        });
    }

    /**
     * The period the roll-over lands in when staff did not name one.
     *
     * Advances the source by one, which is what "next period" means for the
     * annual work this exists for. A source with no period stays without one
     * rather than inventing a year from the calendar — the dossier may well be
     * for a period that is not the current one.
     */
    private function nextPeriodYear(Dossier $source): ?int
    {
        return $source->period_year === null ? null : $source->period_year + 1;
    }

    /**
     * The source label with its year advanced, when the label carries one.
     *
     * Firms label periods in their own words ("FY2025", "Jaarrekening 2025",
     * "Boekjaar 2025/2026"), so the year is substituted in place rather than
     * reformatted into a shape we picked. A label with no recognisable source
     * year is copied verbatim: guessing at its structure would be worse than
     * letting staff correct one field.
     */
    private function nextPeriodLabel(Dossier $source, ?int $periodYear): ?string
    {
        $label = $source->period_label;

        if (! is_string($label) || $label === '' || $source->period_year === null || $periodYear === null) {
            return $label;
        }

        $sourceYear = (string) $source->period_year;

        if (! str_contains($label, $sourceYear)) {
            return $label;
        }

        return str_replace($sourceYear, (string) $periodYear, $label);
    }

    /**
     * @param  Collection<int, DocumentRequest>  $sourceDocumentRequests
     */
    private function cloneDocumentRequests(
        Collection $sourceDocumentRequests,
        Dossier $rolledOver,
        bool $carryAnswers,
    ): void {
        // One fresh ULID per source request, keyed by source id.
        //
        // document_requests.field_key is globally unique, so keys cannot be
        // reused. Keying this map by field_key instead of id would collapse
        // whenever a source key is null or duplicated (seeders running under
        // WithoutModelEvents produce both), which then violates that index —
        // the same trap ApplyQuestionnaireTemplateToDossierAction documents.
        $fieldKeysBySourceId = $sourceDocumentRequests
            ->mapWithKeys(fn (DocumentRequest $request): array => [
                $request->getKey() => (string) Str::ulid(),
            ])
            ->all();

        // Visibility rules point at a sibling's field_key, so every rule has to
        // be rewritten to the sibling's *new* key or the clone would reference
        // questions on last period's dossier.
        $fieldKeysForVisibility = $sourceDocumentRequests
            ->filter(fn (DocumentRequest $request): bool => is_string($request->field_key) && $request->field_key !== '')
            ->mapWithKeys(fn (DocumentRequest $request): array => [
                $request->field_key => $fieldKeysBySourceId[$request->getKey()],
            ])
            ->all();

        foreach ($sourceDocumentRequests as $sourceDocumentRequest) {
            $rolledOver->documentRequests()->create([
                'type' => $sourceDocumentRequest->type,
                'field_key' => $fieldKeysBySourceId[$sourceDocumentRequest->getKey()],
                'title' => $sourceDocumentRequest->title,
                'instructions' => $sourceDocumentRequest->instructions,
                'configuration' => $this->remapVisibility(
                    $sourceDocumentRequest->configuration ?? [],
                    $fieldKeysForVisibility,
                ),
                'sort_order' => $sourceDocumentRequest->sort_order,
                'status' => DocumentRequestStatus::Pending,
                ...$this->carriedAnswer($sourceDocumentRequest, $carryAnswers),
            ]);
        }
    }

    /**
     * The answer columns a cloned request starts life with.
     *
     * Returns an empty array when nothing carries, so the new row keeps its
     * column defaults: no answer, no answered_at, and none of the review
     * verdict (reviewed_by, reviewed_at, rejection_reason), which belongs to
     * the period the answer was given in.
     *
     * @return array<string, mixed>
     */
    private function carriedAnswer(DocumentRequest $sourceDocumentRequest, bool $carryAnswers): array
    {
        if (! $carryAnswers || ! $sourceDocumentRequest->type->carriesAnswerAcrossPeriods()) {
            return [];
        }

        $type = $sourceDocumentRequest->type;

        // An unanswered source question carries nothing, and must not be
        // stamped as carried forward either — the portal would otherwise offer
        // the client an empty previous answer to confirm.
        //
        // Emptiness is tested per column, never with blank(): blank(false) is
        // true, and `false` is a perfectly good answer to a yes/no question.
        $answer = match (true) {
            $type->storesAnswerText() => $sourceDocumentRequest->answer_text === null
                ? []
                : ['answer_text' => $sourceDocumentRequest->answer_text],
            $type->storesAnswerOptionIds() => $sourceDocumentRequest->answer_option_ids === null
                || $sourceDocumentRequest->answer_option_ids === []
                    ? []
                    : ['answer_option_ids' => $sourceDocumentRequest->answer_option_ids],
            default => $sourceDocumentRequest->answer_boolean === null
                ? []
                : ['answer_boolean' => $sourceDocumentRequest->answer_boolean],
        };

        if ($answer === []) {
            return [];
        }

        return [...$answer, 'carried_forward_at' => now()];
    }

    /**
     * Point a visibility rule at the sibling's new field key.
     *
     * @param  array<string, mixed>  $configuration
     * @param  array<string, string>  $fieldKeys  Source field key => new field key
     * @return array<string, mixed>
     */
    private function remapVisibility(array $configuration, array $fieldKeys): array
    {
        $sourceFieldKey = $configuration['visibility']['source_field_key'] ?? null;

        if (! is_string($sourceFieldKey) || ! array_key_exists($sourceFieldKey, $fieldKeys)) {
            return $configuration;
        }

        $configuration['visibility']['source_field_key'] = $fieldKeys[$sourceFieldKey];

        return $configuration;
    }
}

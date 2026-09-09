<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\DocumentRequests;

use App\Dossiers\Enums\SubmissionContext;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Transitions\DocumentRequests\DocumentRequestTransitions;

final readonly class SubmitQuestionnaireAnswerAction
{
    public function __construct(
        private DocumentRequestTransitions $documentRequestTransitions,
    ) {}

    /**
     * Store a text, boolean, or option answer and mark the request as submitted.
     *
     * @param  list<string>|null  $answerOptionIds
     */
    public function handle(
        DocumentRequest $documentRequest,
        ?string $answerText = null,
        ?bool $answerBoolean = null,
        ?array $answerOptionIds = null,
        SubmissionContext $context = SubmissionContext::Staff,
    ): DocumentRequest {
        $this->documentRequestTransitions->submitAnswer(
            $documentRequest,
            $context,
            $answerText,
            $answerBoolean,
            $answerOptionIds,
        );

        return $documentRequest->fresh() ?? $documentRequest;
    }
}

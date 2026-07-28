<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\DocumentRequests;

use App\ClientPortal\Enums\SubmissionContext;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Transitions\DocumentRequestTransitions;

final class SubmitQuestionnaireAnswerAction
{
    public function __construct(
        private readonly DocumentRequestTransitions $documentRequestTransitions,
    ) {}

    /**
     * Store a text or boolean answer and mark the request as submitted.
     */
    public function handle(
        DocumentRequest $documentRequest,
        ?string $answerText = null,
        ?bool $answerBoolean = null,
        SubmissionContext $context = SubmissionContext::Staff,
    ): DocumentRequest {
        $this->documentRequestTransitions->submitAnswer(
            $documentRequest,
            $context,
            $answerText,
            $answerBoolean,
        );

        return $documentRequest->fresh() ?? $documentRequest;
    }
}

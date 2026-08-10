<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Enums\SubmissionContext;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Transitions\DocumentRequestTransitions;
use App\Dossiers\Transitions\DossierTransitions;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

covers(DossierTransitions::class, DocumentRequestTransitions::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->client = Client::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->dossier = Dossier::factory()->create([
        'tenant_id' => $this->tenant->id,
        'client_id' => $this->client->id,
    ]);
    $this->dossierTransitions = resolve(DossierTransitions::class);
    $this->documentRequestTransitions = resolve(DocumentRequestTransitions::class);
});

test('a dossier advances without regressing from later open stages', function (): void {
    $this->dossierTransitions->markAwaitingClient($this->dossier);

    expect($this->dossier->status)->toBe(DossierStatus::AwaitingClient);

    $this->dossierTransitions->markInReview($this->dossier);
    $this->dossierTransitions->markAwaitingClient($this->dossier);

    expect($this->dossier->status)->toBe(DossierStatus::InReview);

    $this->dossierTransitions->complete($this->dossier);

    expect($this->dossier->status)->toBe(DossierStatus::Completed)
        ->and(fn () => $this->dossierTransitions->markAwaitingClient($this->dossier))
        ->toThrow(ValidationException::class, 'A completed dossier cannot receive a new portal invitation.')
        ->and(fn () => $this->dossierTransitions->markInReview($this->dossier))
        ->toThrow(ValidationException::class, 'A completed dossier cannot return to review.')
        ->and(fn () => $this->dossierTransitions->complete($this->dossier))
        ->toThrow(ValidationException::class, 'This dossier is already completed.');
});

test('a dossier must be in review before it can be completed', function (): void {
    expect(fn () => $this->dossierTransitions->complete($this->dossier))
        ->toThrow(ValidationException::class, 'Only a dossier in review can be completed.');

    $this->dossierTransitions->markAwaitingClient($this->dossier);

    expect(fn () => $this->dossierTransitions->complete($this->dossier))
        ->toThrow(ValidationException::class, 'Only a dossier in review can be completed.');
});

test('an answer can be reviewed and a rejected answer can be corrected', function (): void {
    $reviewer = User::factory()->create();
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $this->tenant->id,
        'dossier_id' => $this->dossier->id,
        'type' => QuestionnaireItemType::Text,
    ]);

    $this->documentRequestTransitions->submitAnswer(
        $documentRequest,
        SubmissionContext::Staff,
        ' First answer ',
    );
    $this->documentRequestTransitions->reject($documentRequest, $reviewer, ' Add the registered address. ');

    expect($documentRequest->status)->toBe(DocumentRequestStatus::Rejected)
        ->and($documentRequest->answer_text)->toBe('First answer')
        ->and($documentRequest->rejection_reason)->toBe('Add the registered address.');

    $this->documentRequestTransitions->submitAnswer(
        $documentRequest,
        SubmissionContext::Staff,
        'Corrected answer',
    );

    expect($documentRequest->status)->toBe(DocumentRequestStatus::Submitted)
        ->and($documentRequest->answer_text)->toBe('Corrected answer')
        ->and($documentRequest->reviewed_by)->toBeNull()
        ->and($documentRequest->reviewed_at)->toBeNull()
        ->and($documentRequest->rejection_reason)->toBeNull();

    $this->documentRequestTransitions->accept($documentRequest, $reviewer);

    expect($documentRequest->status)->toBe(DocumentRequestStatus::Accepted)
        ->and($documentRequest->reviewed_by)->toBe($reviewer->id)
        ->and(fn () => $this->documentRequestTransitions->submitAnswer(
            $documentRequest,
            SubmissionContext::Staff,
            'Another answer',
        ))
        ->toThrow(ValidationException::class, 'An approved item cannot be submitted again.');
});

test('only submitted items can be accepted or rejected', function (): void {
    $reviewer = User::factory()->create();
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $this->tenant->id,
        'dossier_id' => $this->dossier->id,
        'type' => QuestionnaireItemType::Text,
    ]);

    expect(fn () => $this->documentRequestTransitions->accept($documentRequest, $reviewer))
        ->toThrow(ValidationException::class, 'Only submitted items can be reviewed.');

    $this->documentRequestTransitions->submitAnswer(
        $documentRequest,
        SubmissionContext::Staff,
        'Answer',
    );

    expect(fn () => $this->documentRequestTransitions->reject($documentRequest, $reviewer, '  '))
        ->toThrow(ValidationException::class, 'Explain what the client needs to correct.');
});

test('a submitted upload can be replaced by staff but not from the portal', function (): void {
    $reviewer = User::factory()->create();
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $this->tenant->id,
        'dossier_id' => $this->dossier->id,
        'type' => QuestionnaireItemType::File,
    ]);

    $this->documentRequestTransitions->submitUpload($documentRequest, SubmissionContext::Staff);
    $this->documentRequestTransitions->submitUpload($documentRequest, SubmissionContext::Staff);

    expect($documentRequest->status)->toBe(DocumentRequestStatus::Submitted)
        ->and($documentRequest->answered_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($this->documentRequestTransitions->canSubmit($documentRequest, SubmissionContext::Portal, $this->dossier))
        ->toBeFalse()
        ->and(fn () => $this->documentRequestTransitions->submitUpload(
            $documentRequest,
            SubmissionContext::Portal,
        ))
        ->toThrow(ValidationException::class, 'This item cannot be submitted from the client portal in its current state.');

    $this->documentRequestTransitions->accept($documentRequest, $reviewer);

    expect(fn () => $this->documentRequestTransitions->submitUpload(
        $documentRequest,
        SubmissionContext::Staff,
    ))
        ->toThrow(ValidationException::class, 'An approved item cannot be submitted again.');
});

test('portal submissions are blocked on completed dossiers', function (): void {
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $this->tenant->id,
        'dossier_id' => $this->dossier->id,
        'type' => QuestionnaireItemType::Text,
        'status' => DocumentRequestStatus::Pending,
    ]);

    $this->dossier->update(['status' => DossierStatus::Completed]);

    expect($this->documentRequestTransitions->canSubmit(
        $documentRequest,
        SubmissionContext::Portal,
        $this->dossier->fresh(),
    ))->toBeFalse();
});

test('answer_text types accept text answers', function (QuestionnaireItemType $type, string $answer): void {
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $this->tenant->id,
        'dossier_id' => $this->dossier->id,
        'type' => $type,
    ]);

    $this->documentRequestTransitions->submitAnswer(
        $documentRequest,
        SubmissionContext::Staff,
        $answer,
    );

    expect($documentRequest->fresh())
        ->status->toBe(DocumentRequestStatus::Submitted)
        ->answer_text->toBe($answer)
        ->answer_boolean->toBeNull();
})->with([
    'text' => [QuestionnaireItemType::Text, 'Short answer'],
    'textarea' => [QuestionnaireItemType::Textarea, "Line one\nLine two"],
    'date' => [QuestionnaireItemType::Date, '2026-07-24'],
    'number' => [QuestionnaireItemType::Number, '42.5'],
]);

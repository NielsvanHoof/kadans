<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Enums\SubmissionContext;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Transitions\DocumentRequestTransitions;
use App\Questionnaires\Enums\QuestionnaireItemType;
use Illuminate\Validation\ValidationException;

covers(DocumentRequestTransitions::class);

/*
|--------------------------------------------------------------------------
| DocumentRequestTransitions
|--------------------------------------------------------------------------
|
| Feature tests reach these rules through HTTP, which covers the happy paths but
| not the full status x context matrix. canSubmit() is the single gate every
| portal and staff submission goes through, so it is enumerated exhaustively here
| rather than sampled.
|
*/

beforeEach(function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $this->tenant = $tenant;
    // Nested factories do not resolve under the tenant scope, so the client is explicit.
    $this->client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $this->transitions = resolve(DocumentRequestTransitions::class);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function transitionItem(array $attributes = [], ?DossierStatus $dossierStatus = null): DocumentRequest
{
    $tenant = test()->tenant;

    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => test()->client->id,
        'status' => $dossierStatus ?? DossierStatus::AwaitingClient,
    ]);

    return DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        ...$attributes,
    ]);
}

describe('canSubmit', function (): void {
    /*
     * Portal: only pending or rejected items may be answered, so a client cannot
     * overwrite something already awaiting review or already approved.
     */
    it('lets the portal submit only pending and rejected items', function (
        DocumentRequestStatus $status,
        bool $expected,
    ): void {
        $documentRequest = transitionItem(['status' => $status]);

        expect($this->transitions->canSubmit($documentRequest, SubmissionContext::Portal))
            ->toBe($expected);
    })->with([
        'pending' => [DocumentRequestStatus::Pending, true],
        'rejected' => [DocumentRequestStatus::Rejected, true],
        'submitted (awaiting review)' => [DocumentRequestStatus::Submitted, false],
        'accepted' => [DocumentRequestStatus::Accepted, false],
    ]);

    /*
     * Staff may additionally replace an item that is already submitted (e.g. a
     * re-upload on the client's behalf), but never an approved one.
     */
    it('lets staff also replace submitted items', function (
        DocumentRequestStatus $status,
        bool $expected,
    ): void {
        $documentRequest = transitionItem(['status' => $status]);

        expect($this->transitions->canSubmit($documentRequest, SubmissionContext::Staff))
            ->toBe($expected);
    })->with([
        'pending' => [DocumentRequestStatus::Pending, true],
        'rejected' => [DocumentRequestStatus::Rejected, true],
        'submitted' => [DocumentRequestStatus::Submitted, true],
        'accepted' => [DocumentRequestStatus::Accepted, false],
    ]);

    it('closes the portal once the dossier is completed', function (): void {
        $documentRequest = transitionItem(
            ['status' => DocumentRequestStatus::Pending],
            DossierStatus::Completed,
        );

        expect($this->transitions->canSubmit($documentRequest, SubmissionContext::Portal))->toBeFalse()
            // Staff are not blocked by dossier completion at this gate.
            ->and($this->transitions->canSubmit($documentRequest, SubmissionContext::Staff))->toBeTrue();
    });

    it('refuses a portal submission when the dossier cannot be resolved', function (): void {
        $documentRequest = transitionItem(['status' => DocumentRequestStatus::Pending]);
        $documentRequest->setRelation('dossier', null);

        expect($this->transitions->canSubmit($documentRequest, SubmissionContext::Portal))->toBeFalse();
    });

    it('prefers an explicitly passed dossier over the relation', function (): void {
        $documentRequest = transitionItem(['status' => DocumentRequestStatus::Pending]);
        $completed = Dossier::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'status' => DossierStatus::Completed,
        ]);

        expect($this->transitions->canSubmit($documentRequest, SubmissionContext::Portal, $completed))
            ->toBeFalse();
    });
});

describe('submitAnswer', function (): void {
    it('rejects file items, which must go through upload', function (): void {
        $documentRequest = transitionItem(['type' => QuestionnaireItemType::File]);

        expect(fn () => $this->transitions->submitAnswer(
            $documentRequest,
            SubmissionContext::Staff,
            answerText: 'nope',
        ))->toThrow(InvalidArgumentException::class);
    });

    it('requires non-blank text for text items', function (string $answer): void {
        $documentRequest = transitionItem(['type' => QuestionnaireItemType::Text]);

        expect(fn () => $this->transitions->submitAnswer(
            $documentRequest,
            SubmissionContext::Staff,
            answerText: $answer,
        ))->toThrow(InvalidArgumentException::class);
    })->with([
        'empty' => [''],
        'whitespace only' => ['   '],
    ]);

    it('trims a stored text answer and clears the other answer columns', function (): void {
        $documentRequest = transitionItem([
            'type' => QuestionnaireItemType::Text,
            'answer_boolean' => true,
        ]);

        $this->transitions->submitAnswer(
            $documentRequest,
            SubmissionContext::Staff,
            answerText: '  Rotterdam  ',
        );

        expect($documentRequest->fresh())
            ->answer_text->toBe('Rotterdam')
            ->answer_boolean->toBeNull()
            ->answer_option_ids->toBeNull()
            ->status->toBe(DocumentRequestStatus::Submitted);
    });

    it('requires an explicit yes/no for boolean items', function (): void {
        $documentRequest = transitionItem(['type' => QuestionnaireItemType::Boolean]);

        expect(fn () => $this->transitions->submitAnswer(
            $documentRequest,
            SubmissionContext::Staff,
        ))->toThrow(InvalidArgumentException::class);
    });

    it('stores false as an answer rather than treating it as absent', function (): void {
        $documentRequest = transitionItem(['type' => QuestionnaireItemType::Boolean]);

        $this->transitions->submitAnswer(
            $documentRequest,
            SubmissionContext::Staff,
            answerBoolean: false,
        );

        expect($documentRequest->fresh())
            ->answer_boolean->toBeFalse()
            ->status->toBe(DocumentRequestStatus::Submitted);
    });

    it('clears prior review metadata so a corrected item can be reviewed again', function (): void {
        $reviewer = User::factory()->create();
        $documentRequest = transitionItem([
            'type' => QuestionnaireItemType::Text,
            'status' => DocumentRequestStatus::Rejected,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now()->subDay(),
            'rejection_reason' => 'Too blurry.',
        ]);

        $this->transitions->submitAnswer(
            $documentRequest,
            SubmissionContext::Portal,
            answerText: 'Corrected',
        );

        expect($documentRequest->fresh())
            ->reviewed_by->toBeNull()
            ->reviewed_at->toBeNull()
            ->rejection_reason->toBeNull()
            ->status->toBe(DocumentRequestStatus::Submitted);
    });

    it('refuses to re-answer an accepted item', function (): void {
        $documentRequest = transitionItem([
            'type' => QuestionnaireItemType::Text,
            'status' => DocumentRequestStatus::Accepted,
        ]);

        expect(fn () => $this->transitions->submitAnswer(
            $documentRequest,
            SubmissionContext::Staff,
            answerText: 'again',
        ))->toThrow(ValidationException::class);
    });

    describe('option answers', function (): void {
        /** @return array<string, mixed> */
        function optionConfiguration(): array
        {
            return [
                'is_required' => true,
                'options' => [
                    ['id' => 'a', 'label' => 'A'],
                    ['id' => 'b', 'label' => 'B'],
                ],
            ];
        }

        it('requires at least one selection', function (?array $selection): void {
            $documentRequest = transitionItem([
                'type' => QuestionnaireItemType::Choice,
                'configuration' => optionConfiguration(),
            ]);

            expect(fn () => $this->transitions->submitAnswer(
                $documentRequest,
                SubmissionContext::Staff,
                answerOptionIds: $selection,
            ))->toThrow(InvalidArgumentException::class);
        })->with([
            'null' => [null],
            'empty list' => [[]],
        ]);

        it('allows exactly one option for a single choice', function (): void {
            $documentRequest = transitionItem([
                'type' => QuestionnaireItemType::Choice,
                'configuration' => optionConfiguration(),
            ]);

            expect(fn () => $this->transitions->submitAnswer(
                $documentRequest,
                SubmissionContext::Staff,
                answerOptionIds: ['a', 'b'],
            ))->toThrow(InvalidArgumentException::class);

            $this->transitions->submitAnswer(
                $documentRequest,
                SubmissionContext::Staff,
                answerOptionIds: ['a'],
            );

            expect($documentRequest->fresh()->answer_option_ids)->toBe(['a']);
        });

        it('allows several options for a multi-select', function (): void {
            $documentRequest = transitionItem([
                'type' => QuestionnaireItemType::MultiSelect,
                'configuration' => optionConfiguration(),
            ]);

            $this->transitions->submitAnswer(
                $documentRequest,
                SubmissionContext::Staff,
                answerOptionIds: ['a', 'b'],
            );

            expect($documentRequest->fresh()->answer_option_ids)->toBe(['a', 'b']);
        });

        it('refuses an option that is not on the field', function (): void {
            $documentRequest = transitionItem([
                'type' => QuestionnaireItemType::Choice,
                'configuration' => optionConfiguration(),
            ]);

            expect(fn () => $this->transitions->submitAnswer(
                $documentRequest,
                SubmissionContext::Staff,
                answerOptionIds: ['z'],
            ))->toThrow(InvalidArgumentException::class);
        });
    });
});

describe('submitUpload', function (): void {
    it('only accepts file items', function (): void {
        $documentRequest = transitionItem(['type' => QuestionnaireItemType::Text]);

        expect(fn () => $this->transitions->submitUpload($documentRequest, SubmissionContext::Staff))
            ->toThrow(InvalidArgumentException::class);
    });

    it('lets staff replace an already submitted upload', function (): void {
        $documentRequest = transitionItem([
            'type' => QuestionnaireItemType::File,
            'status' => DocumentRequestStatus::Submitted,
        ]);

        $this->transitions->submitUpload($documentRequest, SubmissionContext::Staff);

        expect($documentRequest->fresh()->status)->toBe(DocumentRequestStatus::Submitted);
    });

    it('blocks the portal from replacing an upload awaiting review', function (): void {
        $documentRequest = transitionItem([
            'type' => QuestionnaireItemType::File,
            'status' => DocumentRequestStatus::Submitted,
        ]);

        expect(fn () => $this->transitions->submitUpload($documentRequest, SubmissionContext::Portal))
            ->toThrow(ValidationException::class);
    });
});

describe('reopenForSubmission', function (): void {
    it('moves a submitted item with no uploads back to pending', function (): void {
        $documentRequest = transitionItem([
            'type' => QuestionnaireItemType::File,
            'status' => DocumentRequestStatus::Submitted,
            'answered_at' => now(),
        ]);

        $this->transitions->reopenForSubmission($documentRequest);

        expect($documentRequest->fresh())
            ->status->toBe(DocumentRequestStatus::Pending)
            ->answered_at->toBeNull();
    });

    it('rejects reopen when the item is not submitted', function (DocumentRequestStatus $status): void {
        $documentRequest = transitionItem([
            'type' => QuestionnaireItemType::File,
            'status' => $status,
        ]);

        expect(fn () => $this->transitions->reopenForSubmission($documentRequest))
            ->toThrow(ValidationException::class);
    })->with([
        'pending' => [DocumentRequestStatus::Pending],
        'rejected' => [DocumentRequestStatus::Rejected],
        'accepted' => [DocumentRequestStatus::Accepted],
    ]);

    it('rejects reopen while uploads still exist', function (): void {
        $documentRequest = transitionItem([
            'type' => QuestionnaireItemType::File,
            'status' => DocumentRequestStatus::Submitted,
        ]);

        UploadedDocument::factory()->create([
            'tenant_id' => $documentRequest->tenant_id,
            'document_request_id' => $documentRequest->id,
        ]);

        expect(fn () => $this->transitions->reopenForSubmission($documentRequest))
            ->toThrow(ValidationException::class);
    });
});

describe('review', function (): void {
    it('only reviews submitted items', function (DocumentRequestStatus $status): void {
        $reviewer = User::factory()->create();
        $documentRequest = transitionItem(['status' => $status]);

        expect(fn () => $this->transitions->accept($documentRequest, $reviewer))
            ->toThrow(ValidationException::class)
            ->and(fn () => $this->transitions->reject($documentRequest, $reviewer, 'Fix it.'))
            ->toThrow(ValidationException::class);
    })->with([
        'pending' => [DocumentRequestStatus::Pending],
        'rejected' => [DocumentRequestStatus::Rejected],
        'accepted' => [DocumentRequestStatus::Accepted],
    ]);

    it('clears any rejection reason on accept', function (): void {
        $reviewer = User::factory()->create();
        $documentRequest = transitionItem([
            'status' => DocumentRequestStatus::Submitted,
            'rejection_reason' => 'Previously rejected.',
        ]);

        $this->transitions->accept($documentRequest, $reviewer);

        expect($documentRequest->fresh())
            ->status->toBe(DocumentRequestStatus::Accepted)
            ->rejection_reason->toBeNull()
            ->reviewed_by->toBe($reviewer->id);
    });

    it('requires a usable rejection reason', function (?string $reason): void {
        $reviewer = User::factory()->create();
        $documentRequest = transitionItem(['status' => DocumentRequestStatus::Submitted]);

        expect(fn () => $this->transitions->reject($documentRequest, $reviewer, $reason))
            ->toThrow(ValidationException::class);
    })->with([
        'null' => [null],
        'empty' => [''],
        'whitespace only' => ['   '],
    ]);

    it('trims the stored rejection reason', function (): void {
        $reviewer = User::factory()->create();
        $documentRequest = transitionItem(['status' => DocumentRequestStatus::Submitted]);

        $this->transitions->reject($documentRequest, $reviewer, '  Page 2 is missing.  ');

        expect($documentRequest->fresh())
            ->status->toBe(DocumentRequestStatus::Rejected)
            ->rejection_reason->toBe('Page 2 is missing.');
    });
});

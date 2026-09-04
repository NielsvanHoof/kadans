<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Dossiers\Actions\RollOverDossierAction;
use App\Dossiers\Data\RollOverDossierData;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Models\UploadedDocument;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;

covers(RollOverDossierAction::class);

/**
 * @return array{tenant: Tenant, dossier: Dossier}
 */
function rollOverSource(array $attributes = []): array
{
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::Completed,
        'reference' => 'JR-2025-001',
        'period_year' => 2025,
        'period_label' => 'Jaarrekening 2025',
        ...$attributes,
    ]);

    return ['tenant' => $tenant, 'dossier' => $dossier];
}

function rollOverData(array $overrides = []): RollOverDossierData
{
    return new RollOverDossierData(
        title: $overrides['title'] ?? 'Jaarrekening 2026',
        reference: $overrides['reference'] ?? 'JR-2026-001',
        periodYear: $overrides['periodYear'] ?? null,
        periodLabel: $overrides['periodLabel'] ?? null,
        dueDate: $overrides['dueDate'] ?? null,
        carryAnswers: $overrides['carryAnswers'] ?? true,
    );
}

function rollOver(Dossier $source, array $overrides = []): Dossier
{
    return resolve(RollOverDossierAction::class)->handle($source, rollOverData($overrides));
}

test('the new dossier belongs to the same client and links back to its source', function (): void {
    ['tenant' => $tenant, 'dossier' => $source] = rollOverSource();

    $rolledOver = rollOver($source);

    expect($rolledOver->is($source))->toBeFalse()
        ->and($rolledOver->tenant_id)->toBe($tenant->id)
        ->and($rolledOver->client_id)->toBe($source->client_id)
        ->and($rolledOver->rolled_from_dossier_id)->toBe($source->getKey())
        ->and($rolledOver->status)->toBe(DossierStatus::Draft)
        ->and($rolledOver->reference)->toBe('JR-2026-001')
        ->and($source->rolledOverDossiers()->pluck('id')->all())->toBe([$rolledOver->getKey()]);
});

test('the period advances by one and the label carries the new year', function (): void {
    ['dossier' => $source] = rollOverSource();

    $rolledOver = rollOver($source);

    expect($rolledOver->period_year)->toBe(2026)
        ->and($rolledOver->period_label)->toBe('Jaarrekening 2026');
});

test('a period staff named explicitly wins over the derived one', function (): void {
    ['dossier' => $source] = rollOverSource();

    $rolledOver = rollOver($source, ['periodYear' => 2030, 'periodLabel' => 'FY2030']);

    expect($rolledOver->period_year)->toBe(2030)
        ->and($rolledOver->period_label)->toBe('FY2030');
});

test('a source without a period produces one without a period', function (): void {
    ['dossier' => $source] = rollOverSource(['period_year' => null, 'period_label' => null]);

    $rolledOver = rollOver($source);

    expect($rolledOver->period_year)->toBeNull()
        ->and($rolledOver->period_label)->toBeNull();
});

test('a label that does not carry the source year is copied verbatim', function (): void {
    ['dossier' => $source] = rollOverSource(['period_label' => 'Boekjaar A']);

    expect(rollOver($source)->period_label)->toBe('Boekjaar A');
});

test('every question is recreated with a fresh field key and the same shape', function (): void {
    ['dossier' => $source] = rollOverSource();

    $question = DocumentRequest::factory()->create([
        'dossier_id' => $source->getKey(),
        'type' => QuestionnaireItemType::Text,
        'title' => 'Legal form',
        'instructions' => 'As registered with the Chamber of Commerce.',
        'sort_order' => 3,
    ]);

    $clone = rollOver($source)->documentRequests()->sole();

    expect($clone->title)->toBe('Legal form')
        ->and($clone->instructions)->toBe('As registered with the Chamber of Commerce.')
        ->and($clone->type)->toBe(QuestionnaireItemType::Text)
        ->and($clone->sort_order)->toBe(3)
        ->and($clone->field_key)->not->toBe($question->field_key)
        ->and($clone->field_key)->not->toBeEmpty();
});

test('a visibility rule points at the new key of the same sibling question', function (): void {
    ['dossier' => $source] = rollOverSource();

    $driver = DocumentRequest::factory()->create([
        'dossier_id' => $source->getKey(),
        'type' => QuestionnaireItemType::Boolean,
        'sort_order' => 0,
    ]);

    DocumentRequest::factory()->create([
        'dossier_id' => $source->getKey(),
        'type' => QuestionnaireItemType::Text,
        'sort_order' => 1,
        'configuration' => [
            'visibility' => [
                'source_field_key' => $driver->field_key,
                'option_ids' => [QuestionnaireItemType::BooleanYesOptionId],
            ],
        ],
    ]);

    $clones = rollOver($source)->documentRequestsInCanvasOrder();
    $clonedDriver = $clones->firstWhere('sort_order', 0);
    $clonedDependent = $clones->firstWhere('sort_order', 1);

    expect($clonedDependent->configuration['visibility']['source_field_key'])
        ->toBe($clonedDriver->field_key)
        ->and($clonedDependent->configuration['visibility']['source_field_key'])
        ->not->toBe($driver->field_key)
        ->and($clonedDependent->configuration['visibility']['option_ids'])
        ->toBe([QuestionnaireItemType::BooleanYesOptionId]);
});

test('answers carry forward into the column their type stores them in', function (
    QuestionnaireItemType $type,
    array $sourceAnswer,
    string $column,
    mixed $expected,
): void {
    ['dossier' => $source] = rollOverSource();

    DocumentRequest::factory()->create([
        'dossier_id' => $source->getKey(),
        'type' => $type,
        'status' => DocumentRequestStatus::Accepted,
        'answered_at' => now()->subYear(),
        ...$sourceAnswer,
    ]);

    $clone = rollOver($source)->documentRequests()->sole();

    expect($clone->{$column})->toBe($expected)
        ->and($clone->carried_forward_at)->not->toBeNull();
})->with([
    'text' => [QuestionnaireItemType::Text, ['answer_text' => 'BV'], 'answer_text', 'BV'],
    'textarea' => [QuestionnaireItemType::Textarea, ['answer_text' => 'Two shareholders.'], 'answer_text', 'Two shareholders.'],
    'date' => [QuestionnaireItemType::Date, ['answer_text' => '2020-01-31'], 'answer_text', '2020-01-31'],
    'number' => [QuestionnaireItemType::Number, ['answer_text' => '42'], 'answer_text', '42'],
    'boolean yes' => [QuestionnaireItemType::Boolean, ['answer_boolean' => true], 'answer_boolean', true],
    'boolean no' => [QuestionnaireItemType::Boolean, ['answer_boolean' => false], 'answer_boolean', false],
    'choice' => [QuestionnaireItemType::Choice, ['answer_option_ids' => ['opt-1']], 'answer_option_ids', ['opt-1']],
    'multi select' => [QuestionnaireItemType::MultiSelect, ['answer_option_ids' => ['opt-1', 'opt-2']], 'answer_option_ids', ['opt-1', 'opt-2']],
]);

test('a carried answer is left pending with no review verdict', function (): void {
    ['dossier' => $source] = rollOverSource();
    $reviewer = workspaceMember($source->tenant, Role::Reviewer);

    DocumentRequest::factory()->create([
        'dossier_id' => $source->getKey(),
        'type' => QuestionnaireItemType::Text,
        'answer_text' => 'BV',
        'status' => DocumentRequestStatus::Rejected,
        'answered_at' => now()->subYear(),
        'reviewed_by' => $reviewer->id,
        'reviewed_at' => now()->subYear(),
        'rejection_reason' => 'Wrong legal form.',
    ]);

    $clone = rollOver($source)->documentRequests()->sole();

    expect($clone->status)->toBe(DocumentRequestStatus::Pending)
        ->and($clone->answer_text)->toBe('BV')
        ->and($clone->answered_at)->toBeNull()
        ->and($clone->reviewed_by)->toBeNull()
        ->and($clone->reviewed_at)->toBeNull()
        ->and($clone->rejection_reason)->toBeNull();
});

test('an unanswered question carries nothing and is not marked carried forward', function (): void {
    ['dossier' => $source] = rollOverSource();

    DocumentRequest::factory()->create([
        'dossier_id' => $source->getKey(),
        'type' => QuestionnaireItemType::Text,
        'answer_text' => null,
    ]);

    $clone = rollOver($source)->documentRequests()->sole();

    expect($clone->answer_text)->toBeNull()
        ->and($clone->carried_forward_at)->toBeNull();
});

test('a file question carries no answer and clones no uploaded document', function (): void {
    ['dossier' => $source] = rollOverSource();

    $fileQuestion = DocumentRequest::factory()->create([
        'dossier_id' => $source->getKey(),
        'type' => QuestionnaireItemType::File,
        'status' => DocumentRequestStatus::Accepted,
        'answered_at' => now()->subYear(),
    ]);

    UploadedDocument::factory()->create([
        'document_request_id' => $fileQuestion->getKey(),
    ]);

    $clone = rollOver($source)->documentRequests()->sole();

    expect($clone->type)->toBe(QuestionnaireItemType::File)
        ->and($clone->status)->toBe(DocumentRequestStatus::Pending)
        ->and($clone->carried_forward_at)->toBeNull()
        ->and($clone->uploadedDocuments()->count())->toBe(0)
        ->and($fileQuestion->uploadedDocuments()->count())->toBe(1);
});

test('opting out of carrying answers produces a structure-only clone', function (): void {
    ['dossier' => $source] = rollOverSource();

    DocumentRequest::factory()->create([
        'dossier_id' => $source->getKey(),
        'type' => QuestionnaireItemType::Text,
        'title' => 'Legal form',
        'answer_text' => 'BV',
        'status' => DocumentRequestStatus::Accepted,
    ]);

    $clone = rollOver($source, ['carryAnswers' => false])->documentRequests()->sole();

    expect($clone->title)->toBe('Legal form')
        ->and($clone->answer_text)->toBeNull()
        ->and($clone->carried_forward_at)->toBeNull()
        ->and($clone->status)->toBe(DocumentRequestStatus::Pending);
});

test('nothing but the questionnaire travels to the new period', function (): void {
    ['tenant' => $tenant, 'dossier' => $source] = rollOverSource([
        'builder_locked_by_user_id' => null,
    ]);

    $question = DocumentRequest::factory()->create([
        'dossier_id' => $source->getKey(),
        'type' => QuestionnaireItemType::File,
    ]);

    UploadedDocument::factory()->create(['document_request_id' => $question->getKey()]);
    AudioRecording::factory()->create(['dossier_id' => $source->getKey()]);
    DossierExport::factory()->create(['dossier_id' => $source->getKey()]);
    ClientAccessGrant::factory()->create(['dossier_id' => $source->getKey()]);

    $source->forceFill([
        'builder_locked_by_user_id' => $tenant->owner_id ?? User::factory()->create()->id,
        'builder_lock_token' => 'lock-token',
        'builder_lock_expires_at' => now()->addMinutes(5),
    ])->save();

    $rolledOver = rollOver($source);

    expect($rolledOver->documentRequests()->count())->toBe(1)
        ->and($rolledOver->audioRecordings()->count())->toBe(0)
        ->and($rolledOver->exports()->count())->toBe(0)
        ->and($rolledOver->clientAccessGrants()->count())->toBe(0)
        ->and($rolledOver->builder_locked_by_user_id)->toBeNull()
        ->and($rolledOver->builder_lock_token)->toBeNull()
        ->and($rolledOver->builder_lock_expires_at)->toBeNull()
        ->and($rolledOver->hasActiveBuilderLock())->toBeFalse();
});

test('the roll-over is recorded in the audit trail against the new dossier', function (): void {
    ['dossier' => $source] = rollOverSource();

    DocumentRequest::factory()->count(2)->create([
        'dossier_id' => $source->getKey(),
        'type' => QuestionnaireItemType::Text,
    ]);

    $rolledOver = rollOver($source);

    $activity = Activity::query()
        ->where('event', AuditEvent::DossierRolledOver->value)
        ->sole();

    expect($activity->subject_id)->toBe($rolledOver->getKey())
        ->and($activity->getProperty('rolled_from_dossier_id'))->toBe($source->getKey())
        ->and($activity->getProperty('document_requests'))->toBe(2)
        ->and($activity->getProperty('answers_carried'))->toBeTrue();
});

test('a roll-over is one transaction, so a failure leaves no partial dossier', function (): void {
    ['dossier' => $source] = rollOverSource();

    DocumentRequest::factory()->create([
        'dossier_id' => $source->getKey(),
        'type' => QuestionnaireItemType::Text,
    ]);

    $dossiersBefore = Dossier::query()->count();

    // A duplicate reference trips the (tenant_id, reference) unique index at
    // insert time, which is the cheapest way to fail mid-transaction.
    expect(fn (): Dossier => rollOver($source, ['reference' => $source->reference]))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(Dossier::query()->count())->toBe($dossiersBefore);
});

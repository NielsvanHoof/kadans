<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\ClientPortal\Actions\CreateClientAccessGrantAction;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Dossiers\Actions\CompleteDossierAction;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Questionnaires\Enums\QuestionnaireItemType;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{owner: User, tenant: mixed, client: Client, dossier: Dossier, plainTextToken: string, grant: ClientAccessGrant}
 */
function createConditionalFormsContext(): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'jane@example.com',
    ]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::InReview,
    ]);

    $result = resolve(CreateClientAccessGrantAction::class)->handle($dossier, $owner, 7);

    return [
        'owner' => $owner,
        'tenant' => $tenant,
        'client' => $client,
        'dossier' => $dossier->fresh(),
        'plainTextToken' => $result['plain_text_token'],
        'grant' => $result['grant'],
    ];
}

test('hidden fields do not block dossier completion and retain answers for audit', function (): void {
    $context = createConditionalFormsContext();
    $context['tenant']->makeCurrent();

    $sourceFieldKey = (string) Str::ulid();
    $dependentFieldKey = (string) Str::ulid();
    $yesOptionId = (string) Str::uuid();
    $noOptionId = (string) Str::uuid();

    $choiceRequest = DocumentRequest::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Choice,
        'title' => 'Do you have employees?',
        'field_key' => $sourceFieldKey,
        'sort_order' => 0,
        'status' => DocumentRequestStatus::Accepted,
        'answer_option_ids' => [$noOptionId],
        'answered_at' => now(),
        'configuration' => [
            'is_required' => true,
            'options' => [
                ['id' => $yesOptionId, 'label' => 'Yes'],
                ['id' => $noOptionId, 'label' => 'No'],
            ],
        ],
    ]);

    $dependentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Text,
        'title' => 'How many employees?',
        'field_key' => $dependentFieldKey,
        'sort_order' => 1,
        'status' => DocumentRequestStatus::Submitted,
        'answer_text' => '12',
        'answered_at' => now(),
        'configuration' => [
            'is_required' => true,
            'visibility' => [
                'source_field_key' => $sourceFieldKey,
                'option_ids' => [$yesOptionId],
            ],
        ],
    ]);

    $this->actingAs($context['owner'])
        ->get(route('workspaces.dossiers.show', [
            'tenant' => $context['tenant'],
            'dossier' => $context['dossier'],
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('dossier.ready_to_complete', true)
            ->where('dossier.document_requests.0.is_visible', true)
            ->where('dossier.document_requests.0.is_applicable', true)
            ->where('dossier.document_requests.1.is_visible', false)
            ->where('dossier.document_requests.1.is_applicable', false)
            ->where('dossier.document_requests.1.answer_text', '12'));

    resolve(CompleteDossierAction::class)->handle(
        $context['dossier']->fresh(),
        $context['owner'],
    );

    expect($context['dossier']->fresh()->status)->toBe(DossierStatus::Completed)
        ->and($dependentRequest->fresh()->answer_text)->toBe('12')
        ->and($choiceRequest->fresh()->answer_option_ids)->toBe([$noOptionId]);
});

test('multi-select visibility matches when any selected option overlaps', function (): void {
    $context = createConditionalFormsContext();
    $context['tenant']->makeCurrent();

    $sourceFieldKey = (string) Str::ulid();
    $dependentFieldKey = (string) Str::ulid();
    $optionA = (string) Str::uuid();
    $optionB = (string) Str::uuid();
    $optionC = (string) Str::uuid();

    $multiSelect = DocumentRequest::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::MultiSelect,
        'title' => 'Which documents do you have?',
        'field_key' => $sourceFieldKey,
        'sort_order' => 0,
        'configuration' => [
            'is_required' => true,
            'options' => [
                ['id' => $optionA, 'label' => 'Passport'],
                ['id' => $optionB, 'label' => 'ID card'],
                ['id' => $optionC, 'label' => 'Driving licence'],
            ],
        ],
    ]);

    $dependent = DocumentRequest::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Text,
        'title' => 'Passport number',
        'field_key' => $dependentFieldKey,
        'sort_order' => 1,
        'configuration' => [
            'is_required' => true,
            'visibility' => [
                'source_field_key' => $sourceFieldKey,
                'option_ids' => [$optionA],
            ],
        ],
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->get(route('portal.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('dossier.document_requests', 1));

    $this->post(route('portal.document-requests.answer', [
        'documentRequest' => $multiSelect,
    ]), [
        'answer_option_ids' => [$optionB, $optionA],
    ])->assertRedirect(route('portal.show'));

    $this->get(route('portal.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('dossier.document_requests', 2)
            ->where('dossier.document_requests.1.id', $dependent->id));
});

test('visibility cannot depend on a later field', function (): void {
    $context = createConditionalFormsContext();
    $context['tenant']->makeCurrent();

    $earlierKey = (string) Str::ulid();
    $laterKey = (string) Str::ulid();
    $optionId = (string) Str::uuid();

    $earlier = DocumentRequest::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Text,
        'title' => 'Earlier field',
        'field_key' => $earlierKey,
        'sort_order' => 0,
    ]);

    DocumentRequest::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Choice,
        'title' => 'Later choice',
        'field_key' => $laterKey,
        'sort_order' => 1,
        'configuration' => [
            'is_required' => true,
            'options' => [
                ['id' => $optionId, 'label' => 'Yes'],
                ['id' => (string) Str::uuid(), 'label' => 'No'],
            ],
        ],
    ]);

    grantDossierBuilderLock($context['dossier'], $context['owner']);

    $this->actingAs($context['owner'])
        ->put(route('workspaces.dossiers.document-requests.update', [
            'tenant' => $context['tenant'],
            'dossier' => $context['dossier'],
            'documentRequest' => $earlier,
        ]), [
            'type' => QuestionnaireItemType::Text->value,
            'title' => 'Earlier field',
            'instructions' => null,
            'configuration' => [
                'is_required' => true,
                'visibility' => [
                    'source_field_key' => $laterKey,
                    'option_ids' => [$optionId],
                ],
            ],
        ])
        ->assertSessionHasErrors('configuration.visibility.source_field_key');
});

test('a new field cannot be created with visibility depending on a later field', function (): void {
    $context = createConditionalFormsContext();
    $context['tenant']->makeCurrent();

    $laterKey = (string) Str::ulid();
    $optionId = (string) Str::uuid();

    // The only existing field sits at index 0, so inserting before it makes that
    // field a later one — and therefore an invalid visibility source.
    DocumentRequest::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Choice,
        'title' => 'Later choice',
        'field_key' => $laterKey,
        'sort_order' => 0,
        'configuration' => [
            'is_required' => true,
            'options' => [
                ['id' => $optionId, 'label' => 'Yes'],
                ['id' => (string) Str::uuid(), 'label' => 'No'],
            ],
        ],
    ]);

    grantDossierBuilderLock($context['dossier'], $context['owner']);

    $this->actingAs($context['owner'])
        ->post(route('workspaces.dossiers.document-requests.store', [
            'tenant' => $context['tenant'],
            'dossier' => $context['dossier'],
        ]), [
            'type' => QuestionnaireItemType::Text->value,
            'title' => 'New first field',
            'instructions' => null,
            'position' => 0,
            'configuration' => [
                'is_required' => true,
                'visibility' => [
                    'source_field_key' => $laterKey,
                    'option_ids' => [$optionId],
                ],
            ],
        ])
        ->assertSessionHasErrors('configuration.visibility.source_field_key');

    expect($context['dossier']->documentRequests()->count())->toBe(1);
});

test('an answer is rejected when it selects an option the field does not offer', function (): void {
    $context = createConditionalFormsContext();
    $context['tenant']->makeCurrent();

    $configuredOptionId = (string) Str::uuid();

    $choice = DocumentRequest::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Choice,
        'title' => 'Do you have employees?',
        'sort_order' => 0,
        'configuration' => [
            'is_required' => true,
            'options' => [
                ['id' => $configuredOptionId, 'label' => 'Yes'],
                ['id' => (string) Str::uuid(), 'label' => 'No'],
            ],
        ],
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    // A well-formed uuid that belongs to no option on this field.
    $this->post(route('portal.document-requests.answer', [
        'documentRequest' => $choice,
    ]), [
        'answer_option_ids' => [(string) Str::uuid()],
    ])->assertSessionHasErrors('answer_option_ids.0');

    expect($choice->fresh()->answer_option_ids)->toBeNull();

    $this->post(route('portal.document-requests.answer', [
        'documentRequest' => $choice,
    ]), [
        'answer_option_ids' => [$configuredOptionId],
    ])->assertRedirect(route('portal.show'));

    expect($choice->fresh()->answer_option_ids)->toBe([$configuredOptionId]);
});

test('completing a dossier fails when an applicable field is still pending', function (): void {
    $context = createConditionalFormsContext();
    $context['tenant']->makeCurrent();

    DocumentRequest::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Text,
        'status' => DocumentRequestStatus::Pending,
    ]);

    expect(fn () => resolve(CompleteDossierAction::class)->handle(
        $context['dossier']->fresh(),
        $context['owner'],
    ))->toThrow(ValidationException::class);
});

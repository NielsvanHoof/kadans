<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\ClientPortal\Actions\CreateClientAccessGrantAction;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Questionnaires\Enums\QuestionnaireTemplateCategory;
use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Questionnaires\Models\QuestionnaireTemplateItem;
use App\Tenancy\Actions\ProvisionTenantAction;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\TenantMembership;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SystemQuestionnaireTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

pest()
    ->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('staff can view system and firm templates', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $this->seed(SystemQuestionnaireTemplateSeeder::class);

    $tenant->makeCurrent();
    QuestionnaireTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Firm KYC copy',
        'category' => QuestionnaireTemplateCategory::Kyc,
    ]);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->get(workspaceRoute('workspaces.templates.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('questionnaires/index')
            ->has('system_templates.data', 4)
            ->where('system_templates.total', 4)
            ->has('firm_templates.data', 1)
            ->where('firm_templates.total', 1)
            ->has('indexQuery')
            ->where('can_manage', true));
});

test('system templates cannot be updated or deleted', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $this->seed(SystemQuestionnaireTemplateSeeder::class);

    $system = QuestionnaireTemplate::query()->whereNull('tenant_id')->firstOrFail();

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->put(workspaceRoute('workspaces.templates.update', $tenant, ['template' => $system]), [
            'name' => 'Hacked',
            'description' => 'Nope',
            'category' => QuestionnaireTemplateCategory::Custom->value,
        ])
        ->assertForbidden();

    $this->delete(workspaceRoute('workspaces.templates.destroy', $tenant, ['template' => $system]))
        ->assertForbidden();

    expect($system->fresh()->name)->not->toBe('Hacked');
});

test('owner can copy a system template into the firm', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $this->seed(SystemQuestionnaireTemplateSeeder::class);

    $system = QuestionnaireTemplate::query()
        ->whereNull('tenant_id')
        ->where('category', QuestionnaireTemplateCategory::Kyc->value)
        ->firstOrFail();

    $itemCount = $system->items()->count();

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post(workspaceRoute('workspaces.templates.copy', $tenant, ['template' => $system]))
        ->assertRedirect();

    $tenant->makeCurrent();
    $copy = QuestionnaireTemplate::query()
        ->where('tenant_id', $tenant->id)
        ->where('source_template_id', $system->id)
        ->sole();

    expect($copy->name)->toBe($system->name)
        ->and($copy->items()->count())->toBe($itemCount)
        ->and($copy->isSystem())->toBeFalse();

    // Second copy is allowed.
    $this->post(workspaceRoute('workspaces.templates.copy', $tenant, ['template' => $system]))
        ->assertRedirect();

    expect(QuestionnaireTemplate::query()
        ->where('tenant_id', $tenant->id)
        ->where('source_template_id', $system->id)
        ->count())->toBe(2);
});

test('read only staff cannot copy templates', function (): void {
    $owner = User::factory()->create();
    $reader = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $reader->id,
        'status' => TenantMembershipStatus::Active,
        'joined_at' => now(),
    ]);

    setPermissionsTeamId($tenant->id);
    $reader->assignRole(Role::ReadOnly->value);

    $this->seed(SystemQuestionnaireTemplateSeeder::class);
    $system = QuestionnaireTemplate::query()->whereNull('tenant_id')->firstOrFail();

    $this->actingAs($reader)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post(workspaceRoute('workspaces.templates.copy', $tenant, ['template' => $system]))
        ->assertForbidden();
});

test('adviser can apply a template and edit dossier items', function (): void {
    $owner = User::factory()->create();
    $adviser = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $adviser->id,
        'status' => TenantMembershipStatus::Active,
        'joined_at' => now(),
    ]);

    setPermissionsTeamId($tenant->id);
    $adviser->assignRole(Role::Adviser->value);

    $this->seed(SystemQuestionnaireTemplateSeeder::class);

    $tenant->makeCurrent();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'title' => 'Existing ad-hoc request',
        'sort_order' => 0,
    ]);

    $system = QuestionnaireTemplate::query()
        ->whereNull('tenant_id')
        ->where('category', QuestionnaireTemplateCategory::Kyc->value)
        ->firstOrFail();

    grantDossierBuilderLock($dossier, $adviser);

    $this->actingAs($adviser)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post(workspaceRoute('workspaces.dossiers.apply-template', $tenant, [
            'dossier' => $dossier,
        ]), [
            'questionnaire_template_id' => $system->id,
        ])
        ->assertRedirect(workspaceRoute('workspaces.dossiers.builder', $tenant, ['dossier' => $dossier]));

    $tenant->makeCurrent();
    $requests = DocumentRequest::query()
        ->where('dossier_id', $dossier->id)
        ->orderBy('sort_order')
        ->get();

    expect($requests)->toHaveCount(1 + $system->items()->count())
        ->and($requests->first()->title)->toBe('Existing ad-hoc request')
        ->and($requests->pluck('type')->contains(QuestionnaireItemType::Boolean))->toBeTrue()
        ->and($requests->skip(1)->pluck('field_key')->filter()->unique()->count())
        ->toBe($system->items()->count())
        ->and(Activity::query()
            ->where('event', AuditEvent::DocumentRequestCreated->value)
            ->count())->toBe($system->items()->count());

    $firstTemplateItem = $requests->skip(1)->first();

    $this->put(workspaceRoute('workspaces.dossiers.document-requests.update', $tenant, [
        'dossier' => $dossier,
        'documentRequest' => $firstTemplateItem,
    ]), [
        'type' => QuestionnaireItemType::Text->value,
        'title' => 'Customised title',
        'instructions' => 'Updated instructions',
    ])->assertRedirect(workspaceRoute('workspaces.dossiers.builder', $tenant, ['dossier' => $dossier]));

    expect($firstTemplateItem->fresh()->title)->toBe('Customised title');

    $this->delete(workspaceRoute('workspaces.dossiers.document-requests.destroy', $tenant, [
        'dossier' => $dossier,
        'documentRequest' => $firstTemplateItem,
    ]))->assertRedirect(workspaceRoute('workspaces.dossiers.builder', $tenant, ['dossier' => $dossier]));

    expect(DocumentRequest::query()->whereKey($firstTemplateItem->id)->exists())->toBeFalse()
        ->and(Activity::query()
            ->where('event', AuditEvent::DocumentRequestDeleted->value)
            ->where('subject_id', $firstTemplateItem->id)
            ->exists())->toBeTrue();
});

test('applying a template assigns unique field keys when template items have null keys', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    // Mimic DatabaseSeeder's WithoutModelEvents: items land without field_key.
    $template = QuestionnaireTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Broken seed pack',
        'category' => QuestionnaireTemplateCategory::Kyc,
    ]);

    QuestionnaireTemplateItem::withoutEvents(function () use ($template): void {
        foreach (['Source question', 'Dependent question'] as $index => $title) {
            QuestionnaireTemplateItem::query()->create([
                'questionnaire_template_id' => $template->id,
                'type' => $index === 0
                    ? QuestionnaireItemType::Boolean
                    : QuestionnaireItemType::Text,
                'field_key' => null,
                'title' => $title,
                'instructions' => null,
                'sort_order' => $index,
            ]);
        }
    });

    expect($template->items()->whereNull('field_key')->count())->toBe(2);

    grantDossierBuilderLock($dossier, $owner);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post(workspaceRoute('workspaces.dossiers.apply-template', $tenant, [
            'dossier' => $dossier,
        ]), [
            'questionnaire_template_id' => $template->id,
        ])
        ->assertRedirect(workspaceRoute('workspaces.dossiers.builder', $tenant, ['dossier' => $dossier]));

    $tenant->makeCurrent();
    $fieldKeys = DocumentRequest::query()
        ->where('dossier_id', $dossier->id)
        ->pluck('field_key');

    expect($fieldKeys)->toHaveCount(2)
        ->and($fieldKeys->contains(null))->toBeFalse()
        ->and($fieldKeys->unique()->count())->toBe(2);
});

test('system questionnaire seeder assigns unique field keys without model events', function (): void {
    // DatabaseSeeder uses WithoutModelEvents; reproduce that so the creating
    // hook cannot silently paper over a missing field_key in the seeder.
    QuestionnaireTemplateItem::withoutEvents(function (): void {
        $this->seed(SystemQuestionnaireTemplateSeeder::class);
    });

    $keys = QuestionnaireTemplateItem::query()->pluck('field_key');

    expect($keys)->not->toBeEmpty()
        ->and($keys->contains(null))->toBeFalse()
        ->and($keys->unique()->count())->toBe($keys->count());
});

test('firm templates are isolated between tenants', function (): void {
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    $tenantA = resolve(ProvisionTenantAction::class)->handle('Tenant A', $ownerA);
    $tenantB = resolve(ProvisionTenantAction::class)->handle('Tenant B', $ownerB);

    $tenantA->makeCurrent();
    $templateA = QuestionnaireTemplate::factory()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Secret A',
    ]);

    $this->actingAs($ownerB)
        ->withSession(['active_tenant_id' => $tenantB->id])
        ->get(workspaceRoute('workspaces.templates.show', $tenantB, ['template' => $templateA]))
        ->assertNotFound();
});

test('owner can manage firm template items', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    $template = QuestionnaireTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Custom pack',
    ]);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post(workspaceRoute('workspaces.templates.items.store', $tenant, ['template' => $template]), [
            'type' => QuestionnaireItemType::Boolean->value,
            'title' => 'Confirm completeness',
            'instructions' => 'Yes or no',
        ])
        ->assertRedirect(workspaceRoute('workspaces.templates.show', $tenant, ['template' => $template]));

    $item = QuestionnaireTemplateItem::query()->where('questionnaire_template_id', $template->id)->sole();

    expect($item->type)->toBe(QuestionnaireItemType::Boolean)
        ->and($item->title)->toBe('Confirm completeness');
});

test('owner can reorder every firm template item', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    $template = QuestionnaireTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Custom pack',
    ]);
    $otherTemplate = QuestionnaireTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Other pack',
    ]);
    $items = collect(range(0, 2))
        ->map(fn (int $sortOrder): QuestionnaireTemplateItem => QuestionnaireTemplateItem::factory()->create([
            'questionnaire_template_id' => $template->id,
            'sort_order' => $sortOrder,
        ]));
    $foreignItem = QuestionnaireTemplateItem::factory()->create([
        'questionnaire_template_id' => $otherTemplate->id,
    ]);
    $reorderedIds = $items->pluck('id')->reverse()->values()->all();
    $showRoute = workspaceRoute('workspaces.templates.show', $tenant, ['template' => $template]);
    $reorderRoute = workspaceRoute('workspaces.templates.items.reorder', $tenant, ['template' => $template]);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post($reorderRoute, ['item_ids' => $reorderedIds])
        ->assertRedirect($showRoute);

    expect(QuestionnaireTemplateItem::query()
        ->whereBelongsTo($template, 'template')
        ->oldest('sort_order')
        ->pluck('id')
        ->all())->toBe($reorderedIds);

    $this->from($showRoute)
        ->post($reorderRoute, [
            'item_ids' => [
                $reorderedIds[0],
                $reorderedIds[1],
                $foreignItem->id,
            ],
        ])
        ->assertRedirect($showRoute)
        ->assertSessionHasErrors('item_ids');

    expect(QuestionnaireTemplateItem::query()
        ->whereBelongsTo($template, 'template')
        ->oldest('sort_order')
        ->pluck('id')
        ->all())->toBe($reorderedIds);
});

test('guest can submit text, date, and boolean answers through the portal', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::AwaitingClient,
    ]);

    $textRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'type' => QuestionnaireItemType::Text,
        'title' => 'Address',
        'status' => DocumentRequestStatus::Pending,
    ]);
    $booleanRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'type' => QuestionnaireItemType::Boolean,
        'title' => 'UBO confirmed',
        'status' => DocumentRequestStatus::Pending,
    ]);
    $dateRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'type' => QuestionnaireItemType::Date,
        'title' => 'Incorporation date',
        'status' => DocumentRequestStatus::Pending,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'type' => QuestionnaireItemType::File,
        'title' => 'Identity document',
        'status' => DocumentRequestStatus::Pending,
    ]);

    $result = resolve(CreateClientAccessGrantAction::class)->handle($dossier, $owner, 7);
    $plainTextToken = $result['plain_text_token'];
    $grant = $result['grant'];

    // The frontend registry relies on these enum values being serialized unchanged.
    $this->get(route('portal.exchange', $plainTextToken))
        ->assertRedirect(route('portal.show'));

    $this->get(route('portal.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('portal/show')
            ->where('dossier.document_requests', fn (Collection $documentRequests): bool => $documentRequests
                ->pluck('type')
                ->sort()
                ->values()
                ->all() === ['boolean', 'date', 'file', 'text']));

    $this->post(route('portal.document-requests.answer', [
        'documentRequest' => $textRequest->id,
    ]), [
        'answer_text' => 'Keizersgracht 1, Amsterdam',
    ])->assertRedirect(route('portal.show'));

    $this->post(route('portal.document-requests.answer', [
        'documentRequest' => $booleanRequest->id,
    ]), [
        'answer_boolean' => true,
    ])->assertRedirect(route('portal.show'));

    $this->post(route('portal.document-requests.answer', [
        'documentRequest' => $dateRequest->id,
    ]), [
        'answer_text' => '2026-07-27',
    ])->assertRedirect(route('portal.show'));

    $tenant->makeCurrent();

    expect($textRequest->fresh())
        ->status->toBe(DocumentRequestStatus::Submitted)
        ->answer_text->toBe('Keizersgracht 1, Amsterdam')
        ->and($booleanRequest->fresh())
        ->status->toBe(DocumentRequestStatus::Submitted)
        ->answer_boolean->toBeTrue()
        ->and($dateRequest->fresh())
        ->status->toBe(DocumentRequestStatus::Submitted)
        ->answer_text->toBe('2026-07-27')
        ->and($dossier->fresh()->status)->toBe(DossierStatus::InReview)
        ->and($grant->fresh()->last_used_at)->not->toBeNull();
});

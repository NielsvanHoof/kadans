<?php

declare(strict_types=1);

use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\ClientPortal\Actions\RecordClientPortalAccessAction;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Actions\UpdateClientAction;
use App\Clients\Models\Client;
use App\Dossiers\Actions\Builder\UpdateDocumentRequestAction;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Queries\TenantHasAnyDossiersQuery;
use App\Questionnaires\Actions\DeleteQuestionnaireTemplateAction;
use App\Questionnaires\Actions\DeleteQuestionnaireTemplateItemAction;
use App\Questionnaires\Actions\UpdateQuestionnaireTemplateAction;
use App\Questionnaires\Actions\UpdateQuestionnaireTemplateItemAction;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Questionnaires\Enums\QuestionnaireTemplateCategory;
use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Questionnaires\Models\QuestionnaireTemplateItem;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('update client action persists profile attributes', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Jane',
        'email' => 'jane@example.com',
    ]);

    resolve(UpdateClientAction::class)->handle($client, [
        'name' => 'Jane Updated',
        'email' => 'jane.updated@example.com',
    ]);

    expect($client->fresh())
        ->name->toBe('Jane Updated')
        ->email->toBe('jane.updated@example.com');
});

test('tenant has any dossiers query reports emptiness for a fresh workspace', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    expect(resolve(TenantHasAnyDossiersQuery::class)->handle())->toBeFalse();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    expect(resolve(TenantHasAnyDossiersQuery::class)->handle())->toBeTrue();
});

test('record client portal access stamps grant dossier and audit', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'last_client_opened_at' => null,
    ]);
    $grant = ClientAccessGrant::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'created_by' => $owner->id,
        'last_used_at' => null,
    ]);

    resolve(RecordClientPortalAccessAction::class)->handle($grant);

    expect($grant->fresh()->last_used_at)->not->toBeNull()
        ->and($dossier->fresh()->last_client_opened_at)->not->toBeNull()
        ->and(Activity::query()
            ->where('event', AuditEvent::ClientPortalAccessed->value)
            ->where('subject_id', $grant->id)
            ->exists())->toBeTrue();
});

test('update document request action persists builder attributes', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'title' => 'Old title',
    ]);

    resolve(UpdateDocumentRequestAction::class)->handle($documentRequest, [
        'type' => QuestionnaireItemType::Text->value,
        'title' => 'New title',
        'instructions' => 'Please answer',
    ]);

    expect($documentRequest->fresh())
        ->title->toBe('New title')
        ->instructions->toBe('Please answer')
        ->type->toBe(QuestionnaireItemType::Text);
});

test('questionnaire template update and delete actions persist and audit', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $template = QuestionnaireTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Custom pack',
        'category' => QuestionnaireTemplateCategory::Custom,
    ]);

    resolve(UpdateQuestionnaireTemplateAction::class)->handle($template, [
        'name' => 'Renamed pack',
        'description' => 'Updated',
        'category' => QuestionnaireTemplateCategory::Kyc->value,
    ]);

    expect($template->fresh()->name)->toBe('Renamed pack');

    resolve(DeleteQuestionnaireTemplateAction::class)->handle($template);

    expect(QuestionnaireTemplate::query()->whereKey($template->id)->exists())->toBeFalse()
        ->and(Activity::query()
            ->where('event', AuditEvent::QuestionnaireTemplateDeleted->value)
            ->where('subject_id', $template->id)
            ->exists())->toBeTrue();
});

test('questionnaire template item update and delete actions persist and audit', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $template = QuestionnaireTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Custom pack',
    ]);
    $item = QuestionnaireTemplateItem::factory()->create([
        'questionnaire_template_id' => $template->id,
        'title' => 'Old item',
    ]);

    resolve(UpdateQuestionnaireTemplateItemAction::class)->handle($item, [
        'type' => QuestionnaireItemType::Text->value,
        'title' => 'Updated item',
        'instructions' => null,
    ]);

    expect($item->fresh()->title)->toBe('Updated item');

    resolve(DeleteQuestionnaireTemplateItemAction::class)->handle($template, $item);

    expect(QuestionnaireTemplateItem::query()->whereKey($item->id)->exists())->toBeFalse()
        ->and(Activity::query()
            ->where('event', AuditEvent::QuestionnaireTemplateItemDeleted->value)
            ->where('subject_id', $item->id)
            ->exists())->toBeTrue();
});

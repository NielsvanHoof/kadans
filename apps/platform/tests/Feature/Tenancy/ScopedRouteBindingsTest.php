<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Questionnaires\Models\QuestionnaireTemplateItem;
use App\Tenancy\Actions\ProvisionTenantAction;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SystemQuestionnaireTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

pest()
    ->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('document request routes 404 when the request belongs to another dossier', function (): void {
    Storage::fake('local');

    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $otherDossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $foreignRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $otherDossier->id,
        'title' => 'Foreign request',
    ]);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->put(workspaceRoute('workspaces.dossiers.document-requests.update', $tenant, [
            'dossier' => $dossier,
            'documentRequest' => $foreignRequest,
        ]), [
            'title' => 'Hacked',
            'type' => QuestionnaireItemType::File->value,
        ])
        ->assertNotFound();

    $this->post(workspaceRoute('workspaces.dossiers.document-requests.upload', $tenant, [
        'dossier' => $dossier,
        'documentRequest' => $foreignRequest,
    ]), [
        'document' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
    ])->assertNotFound();

    $this->post(workspaceRoute('workspaces.dossiers.document-requests.review', $tenant, [
        'dossier' => $dossier,
        'documentRequest' => $foreignRequest,
    ]), [
        'decision' => 'accepted',
    ])->assertNotFound();

    expect($foreignRequest->fresh()->title)->toBe('Foreign request');
});

test('template item routes 404 when the item belongs to another template', function (): void {
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
    $foreignItem = QuestionnaireTemplateItem::factory()->create([
        'questionnaire_template_id' => $otherTemplate->id,
        'title' => 'Foreign item',
    ]);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->put(workspaceRoute('workspaces.templates.items.update', $tenant, [
            'template' => $template,
            'item' => $foreignItem,
        ]), [
            'type' => QuestionnaireItemType::Text->value,
            'title' => 'Hacked',
            'instructions' => null,
        ])
        ->assertNotFound();

    $this->delete(workspaceRoute('workspaces.templates.items.destroy', $tenant, [
        'template' => $template,
        'item' => $foreignItem,
    ]))->assertNotFound();

    expect(QuestionnaireTemplateItem::query()->whereKey($foreignItem->id)->exists())->toBeTrue()
        ->and($foreignItem->fresh()->title)->toBe('Foreign item');
});

test('scoped template item routes still resolve system templates as the parent', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $this->seed(SystemQuestionnaireTemplateSeeder::class);

    $system = QuestionnaireTemplate::query()->whereNull('tenant_id')->firstOrFail();
    $item = $system->items()->firstOrFail();

    // Binding must succeed for the system template; policy then forbids mutation.
    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->put(workspaceRoute('workspaces.templates.items.update', $tenant, [
            'template' => $system,
            'item' => $item,
        ]), [
            'type' => $item->type->value,
            'title' => 'Hacked system item',
            'instructions' => null,
        ])
        ->assertForbidden();

    expect($item->fresh()->title)->not->toBe('Hacked system item');
});

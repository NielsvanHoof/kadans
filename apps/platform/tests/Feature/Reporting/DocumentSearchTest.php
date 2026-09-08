<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\DocumentProcessing\Ai\Search\DocumentSearchAgent;
use App\DocumentProcessing\Ai\Search\Tools\SearchUploadedDocuments;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantRole;
use App\Tenancy\Models\TenantSetting;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Tools\Request as ToolRequest;

function documentSearchExtractionJson(string $iban = 'NL18 RABO 0123 4567 89'): string
{
    return json_encode([
        'document_type' => 'bank_statement',
        'summary' => 'Monthly salary payments for March.',
        'fields' => [
            [
                'label' => 'IBAN',
                'value' => $iban,
                'confidence' => 0.99,
                'sensitivity' => 'iban',
            ],
            [
                'label' => 'BSN',
                'value' => '123456789',
                'confidence' => 0.91,
                'sensitivity' => 'bsn',
            ],
        ],
        'tables' => [],
        'notes' => [],
        'confidence' => 0.96,
    ], JSON_THROW_ON_ERROR);
}

/**
 * @return array{owner: User, tenant: Tenant, document: UploadedDocument}
 */
function createSearchableUpload(string $iban = 'NL18 RABO 0123 4567 89', string $clientName = 'Acme BV'): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => $clientName,
    ]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Payroll 2026',
    ]);
    $request = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'title' => 'Bank statement request',
    ]);
    $document = UploadedDocument::factory()->processed(documentSearchExtractionJson($iban))->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $request->id,
        'original_filename' => 'salary-march.pdf',
    ]);

    return ['owner' => $owner, 'tenant' => $tenant, 'document' => $document];
}

test('staff can search documents by filename fragment', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'document' => $document] = createSearchableUpload();

    $this->actingAs($owner)
        ->getJson(workspaceRoute('workspaces.document-search.index', $tenant).'?q=salary-march')
        ->assertOk()
        ->assertJsonPath('hits.0.id', $document->id)
        ->assertJsonPath('hits.0.original_filename', 'salary-march.pdf');
});

test('staff can search documents by ocr content without echoing identifiers', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'document' => $document] = createSearchableUpload();

    $this->actingAs($owner)
        ->getJson(workspaceRoute('workspaces.document-search.index', $tenant).'?q=NL18RABO0123456789')
        ->assertOk()
        ->assertJsonPath('hits.0.id', $document->id)
        ->assertJsonPath('hits.0.original_filename', 'salary-march.pdf')
        ->assertJsonPath('hits.0.document_type', 'bank_statement')
        ->assertJsonPath('hits.0.client_name', 'Acme BV')
        ->assertJsonPath('truncated', false)
        ->assertJsonMissingPath('hits.0.extracted_text')
        ->assertJsonMissingPath('hits.0.searchable_text');

    $body = $this->actingAs($owner)
        ->getJson(workspaceRoute('workspaces.document-search.index', $tenant).'?q=NL18RABO0123456789')
        ->json();

    expect(json_encode($body, JSON_THROW_ON_ERROR))
        ->not->toContain('NL18 RABO')
        ->not->toContain('123456789');
});

test('document search is forbidden without the view documents permission', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $member = workspaceMember($tenant, Role::ReadOnly);

    $role = TenantRole::query()->create([
        'name' => 'no-documents',
        'guard_name' => 'web',
        'tenant_id' => $tenant->id,
        'is_system' => false,
    ]);
    $role->syncPermissions([]);
    $member->syncRoles([$role->name]);

    $this->actingAs($member)
        ->getJson(workspaceRoute('workspaces.document-search.index', $tenant).'?q=salary')
        ->assertForbidden();
});

test('document search surfaces truncation when the key limit is exceeded', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    config(['scout.key_limit' => 1]);

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    foreach (['one.pdf', 'two.pdf'] as $filename) {
        $request = DocumentRequest::factory()->create([
            'tenant_id' => $tenant->id,
            'dossier_id' => $dossier->id,
            'title' => 'UniqueNeedle '.$filename,
        ]);
        UploadedDocument::factory()->processed('UniqueNeedle extract')->create([
            'tenant_id' => $tenant->id,
            'document_request_id' => $request->id,
            'original_filename' => $filename,
        ]);
    }

    $this->actingAs($owner)
        ->getJson(workspaceRoute('workspaces.document-search.index', $tenant).'?q=UniqueNeedle')
        ->assertOk()
        ->assertJsonPath('truncated', true)
        ->assertJsonCount(1, 'hits');
});

test('document search stays on the collection driver without hybrid', function (): void {
    expect(config('scout.driver'))->toBe('collection');

    ['owner' => $owner, 'tenant' => $tenant] = createSearchableUpload();

    $this->actingAs($owner)
        ->getJson(workspaceRoute('workspaces.document-search.index', $tenant).'?q=salary')
        ->assertOk();
});

test('document search falls back to keyword when hybrid cannot embed', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'document' => $document] = createSearchableUpload();

    // SQLite has no pgvector, so hybrid() throws. The palette must still
    // return identifier matches instead of 500ing.
    config(['scout.driver' => 'database']);

    $this->actingAs($owner)
        ->getJson(workspaceRoute('workspaces.document-search.index', $tenant).'?q=NL18RABO0123456789')
        ->assertOk()
        ->assertJsonPath('hits.0.id', $document->id);
});

test('document search does not leak another tenant iban', function (): void {
    ['owner' => $ownerA, 'tenant' => $tenantA] = createSearchableUpload('NL18 RABO 0123 4567 89', 'Tenant A Client');
    ['tenant' => $tenantB] = provisionWorkspace('Tenant B');

    actingInWorkspace($tenantB);
    $clientB = Client::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'Tenant B Client']);
    $dossierB = Dossier::factory()->create([
        'tenant_id' => $tenantB->id,
        'client_id' => $clientB->id,
    ]);
    $requestB = DocumentRequest::factory()->create([
        'tenant_id' => $tenantB->id,
        'dossier_id' => $dossierB->id,
    ]);
    UploadedDocument::factory()->processed(documentSearchExtractionJson('NL86 INGB 0002 4455 88'))->create([
        'tenant_id' => $tenantB->id,
        'document_request_id' => $requestB->id,
        'original_filename' => 'foreign.pdf',
    ]);

    actingInWorkspace($tenantA);

    $this->actingAs($ownerA)
        ->getJson(workspaceRoute('workspaces.document-search.index', $tenantA).'?q=NL86INGB0002445588')
        ->assertOk()
        ->assertJsonPath('hits', []);
});

test('the search tool returns metadata without extracted field values', function (): void {
    createSearchableUpload();

    $encoded = (new SearchUploadedDocuments)->handle(new ToolRequest([
        'query' => 'NL18RABO0123456789',
    ]));

    expect($encoded)->toContain('salary-march.pdf')
        ->and($encoded)->not->toContain('NL18 RABO')
        ->and($encoded)->not->toContain('123456789')
        ->and($encoded)->not->toContain('show_url');
});

test('ask is forbidden when document search is disabled', function (): void {
    DocumentSearchAgent::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    TenantSetting::query()->where('tenant_id', $tenant->id)->update([
        'ai_document_search_enabled' => false,
    ]);

    $this->actingAs($owner)
        ->postJson(workspaceRoute('workspaces.document-search.store', $tenant), [
            'question' => 'Which payslips do we have?',
        ])
        ->assertForbidden();

    DocumentSearchAgent::assertNeverPrompted();
});

test('ask returns a cited answer without raw field values', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'document' => $document] = createSearchableUpload();

    DocumentSearchAgent::fake([[
        'answer' => 'The March salary statement is on file.',
        'citations' => [
            [
                'uploaded_document_id' => $document->id,
                'why' => 'Matches the salary question.',
            ],
        ],
    ]]);

    $this->actingAs($owner)
        ->postJson(workspaceRoute('workspaces.document-search.store', $tenant), [
            'question' => 'Do we have a salary statement?',
        ])
        ->assertOk()
        ->assertJsonPath('answer', 'The March salary statement is on file.')
        ->assertJsonPath('citations.0.uploaded_document_id', $document->id)
        ->assertJsonPath('citations.0.original_filename', 'salary-march.pdf');

    DocumentSearchAgent::assertPrompted(
        fn (AgentPrompt $prompt): bool => $prompt->contains('salary statement'),
    );
});

test('uploaded documents search columns exist after migrate', function (): void {
    expect(Schema::hasColumn('uploaded_documents', 'searchable_text'))->toBeTrue()
        ->and(Schema::hasColumn('uploaded_documents', 'embedding'))->toBeTrue();
});

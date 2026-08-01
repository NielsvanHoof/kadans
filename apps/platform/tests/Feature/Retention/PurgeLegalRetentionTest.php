<?php

declare(strict_types=1);

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config([
        'retention.archived_days' => 1095,
    ]);
});

test('expired archived dossier is permanently purged with files and activity log entries', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);
    $uploadedDocument = UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'disk' => 'local',
        'path' => 'uploads/retention-test.pdf',
    ]);

    Storage::disk('local')->put($uploadedDocument->path, 'retention-test-content');

    resolve(LogAuditActivityAction::class)->handle(
        AuditEvent::DossierViewed,
        $dossier,
        [],
        null,
        false,
    );
    resolve(LogAuditActivityAction::class)->handle(
        AuditEvent::DocumentRequestCreated,
        $documentRequest,
        [],
        null,
        false,
    );

    $dossier->delete();
    $purgedAt = now()->subDays(1095)->subDay();

    Dossier::withTrashed()
        ->whereKey($dossier->id)
        ->update(['deleted_at' => $purgedAt]);

    Tenant::forgetCurrent();

    $this->artisan('retention:purge-legal')
        ->assertSuccessful();

    $tenant->makeCurrent();

    expect(Dossier::withTrashed()->find($dossier->id))->toBeNull()
        ->and(DocumentRequest::query()->find($documentRequest->id))->toBeNull()
        ->and(UploadedDocument::query()->find($uploadedDocument->id))->toBeNull()
        ->and(Storage::disk('local')->exists($uploadedDocument->path))->toBeFalse()
        ->and(Activity::query()->where('subject_type', (new Dossier)->getMorphClass())->where('subject_id', $dossier->id)->exists())->toBeFalse()
        ->and(Activity::query()->where('subject_type', (new DocumentRequest)->getMorphClass())->where('subject_id', $documentRequest->id)->exists())->toBeFalse();
});

test('archived dossier younger than retention cutoff is not purged', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $dossier->delete();
    Dossier::withTrashed()
        ->whereKey($dossier->id)
        ->update(['deleted_at' => now()->subDays(30)]);

    $this->artisan('retention:purge-legal')
        ->assertSuccessful();

    expect(Dossier::onlyTrashed()->find($dossier->id))->not->toBeNull();
});

test('expired archived client is purged only after dossiers are hard deleted', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    resolve(LogAuditActivityAction::class)->handle(
        AuditEvent::ClientDeleted,
        $client,
        ['name' => $client->name],
        null,
        false,
    );

    $purgedAt = now()->subDays(1095)->subDay();

    $dossier->delete();
    Dossier::withTrashed()
        ->whereKey($dossier->id)
        ->update(['deleted_at' => $purgedAt]);

    $client->delete();
    Client::withTrashed()
        ->whereKey($client->id)
        ->update(['deleted_at' => $purgedAt]);

    $this->artisan('retention:purge-legal')
        ->assertSuccessful();

    expect(Dossier::withTrashed()->find($dossier->id))->toBeNull()
        ->and(Client::withTrashed()->find($client->id))->toBeNull()
        ->and(Activity::query()->where('subject_type', (new Client)->getMorphClass())->where('subject_id', $client->id)->exists())->toBeFalse();
});

test('archived client is not purged while dossiers still exist', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $dossier->delete();
    Dossier::withTrashed()
        ->whereKey($dossier->id)
        ->update(['deleted_at' => now()->subDays(30)]);

    $client->delete();
    Client::withTrashed()
        ->whereKey($client->id)
        ->update(['deleted_at' => now()->subDays(1095)->subDay()]);

    $this->artisan('retention:purge-legal')
        ->assertSuccessful();

    expect(Dossier::onlyTrashed()->find($dossier->id))->not->toBeNull()
        ->and(Client::onlyTrashed()->find($client->id))->not->toBeNull();
});

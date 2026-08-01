<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\Dossiers\Actions\DocumentRequests\ResolveDocumentTemporaryUrlAction;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Actions\ProvisionTenantAction;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('local disk downloads stream through the application', function (): void {
    Storage::fake('local');

    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);
    $tenant->makeCurrent();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    $path = 'tenants/'.$tenant->id.'/dossiers/'.$dossier->id.'/payslip.pdf';
    Storage::disk('local')->put($path, 'local-file-bytes');

    $uploaded = UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'payslip.pdf',
    ]);

    $this->actingAs($owner)
        ->withSession([
            'active_tenant_id' => $tenant->id,
            'auth.password_confirmed_at' => now()->getTimestamp(),
        ])
        ->get(workspaceRoute('workspaces.uploaded-documents.download', $tenant, [
            'uploadedDocument' => $uploaded,
        ]))
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename=payslip.pdf');

    $this->travel(16)->minutes();

    $this->get(workspaceRoute('workspaces.uploaded-documents.download', $tenant, [
        'uploadedDocument' => $uploaded,
    ]))->assertRedirect(route('password.confirm'));

    expect(Activity::query()
        ->where('event', AuditEvent::DocumentDownloaded->value)
        ->where('subject_id', $uploaded->id)
        ->exists())->toBeTrue();
});

test('local disk previews stream inline without recording a download audit', function (): void {
    Storage::fake('local');

    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);
    $tenant->makeCurrent();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    $path = 'tenants/'.$tenant->id.'/dossiers/'.$dossier->id.'/payslip.pdf';
    Storage::disk('local')->put($path, 'local-file-bytes');

    $uploaded = UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'payslip.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $this->actingAs($owner)
        ->withSession([
            'active_tenant_id' => $tenant->id,
            'auth.password_confirmed_at' => now()->getTimestamp(),
        ])
        ->get(workspaceRoute('workspaces.uploaded-documents.preview', $tenant, [
            'uploadedDocument' => $uploaded,
        ]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition', 'inline; filename=payslip.pdf');

    expect(Activity::query()
        ->where('event', AuditEvent::DocumentDownloaded->value)
        ->where('subject_id', $uploaded->id)
        ->exists())->toBeFalse();
});

test('resolve document temporary url generates a presigned url with attachment disposition', function (): void {
    config()->set('filesystems.disks.s3.driver', 's3');

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('temporaryUrl')
        ->once()
        ->withArgs(fn (string $path, $expiresAt, array $options): bool => $path === 'tenants/1/dossiers/1/file.pdf'
            && ($options['ResponseContentDisposition'] ?? null) === 'attachment; filename="file.pdf"'
            && ($options['ResponseContentType'] ?? null) === 'application/pdf')
        ->andReturn('http://localhost:9000/local/tenants/1/dossiers/1/file.pdf?X-Amz-Signature=abc');

    $filesystems = Mockery::mock(FilesystemManager::class);
    $filesystems->shouldReceive('disk')
        ->once()
        ->with('s3')
        ->andReturn($disk);

    $uploaded = new UploadedDocument([
        'disk' => 's3',
        'path' => 'tenants/1/dossiers/1/file.pdf',
        'original_filename' => 'file.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $url = new ResolveDocumentTemporaryUrlAction($filesystems)->handle(
        $uploaded,
        now()->addMinutes(5),
    );

    expect($url)->toBe('http://localhost:9000/local/tenants/1/dossiers/1/file.pdf?X-Amz-Signature=abc');
});

test('resolve document temporary url can request inline disposition for previews', function (): void {
    config()->set('filesystems.disks.s3.driver', 's3');

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('temporaryUrl')
        ->once()
        ->withArgs(fn (string $path, $expiresAt, array $options): bool => ($options['ResponseContentDisposition'] ?? null) === 'inline; filename="scan.png"'
            && ($options['ResponseContentType'] ?? null) === 'image/png')
        ->andReturn('http://localhost:9000/local/tenants/1/dossiers/1/scan.png?X-Amz-Signature=abc');

    $filesystems = Mockery::mock(FilesystemManager::class);
    $filesystems->shouldReceive('disk')
        ->once()
        ->with('s3')
        ->andReturn($disk);

    $uploaded = new UploadedDocument([
        'disk' => 's3',
        'path' => 'tenants/1/dossiers/1/scan.png',
        'original_filename' => 'scan.png',
        'mime_type' => 'image/png',
    ]);

    $url = new ResolveDocumentTemporaryUrlAction($filesystems)->handle(
        $uploaded,
        now()->addMinutes(5),
        'inline',
    );

    expect($url)->toBe('http://localhost:9000/local/tenants/1/dossiers/1/scan.png?X-Amz-Signature=abc');
});

test('s3 disk reports temporary url support while local does not', function (): void {
    config()->set('filesystems.disks.s3.driver', 's3');
    config()->set('filesystems.disks.local.driver', 'local');

    $resolver = resolve(ResolveDocumentTemporaryUrlAction::class);

    expect($resolver->supportsTemporaryUrl(new UploadedDocument(['disk' => 's3'])))->toBeTrue()
        ->and($resolver->supportsTemporaryUrl(new UploadedDocument(['disk' => 'local'])))->toBeFalse();
});

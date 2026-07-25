<?php

declare(strict_types=1);

use App\Actions\Dossiers\DocumentRequests\ResolveDocumentTemporaryUrlAction;
use App\Actions\Tenancy\ProvisionTenantAction;
use App\Enums\AuditEvent;
use App\Models\Activity;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\Dossier;
use App\Models\UploadedDocument;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('local disk downloads stream through the application', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $tenant = app(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);
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

test('local disk previews stream inline without recording a download audit', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $tenant = app(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);
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

test('resolve document temporary url rewrites the internal minio host for browsers', function () {
    config()->set('filesystems.disks.s3.driver', 's3');
    config()->set('filesystems.disks.s3.endpoint', 'http://minio:9000');
    config()->set('filesystems.disks.s3.url', 'http://localhost:9000');

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('temporaryUrl')
        ->once()
        ->withArgs(function (string $path, $expiresAt, array $options): bool {
            return $path === 'tenants/1/dossiers/1/file.pdf'
                && ($options['ResponseContentDisposition'] ?? null) === 'attachment; filename="file.pdf"'
                && ($options['ResponseContentType'] ?? null) === 'application/pdf';
        })
        ->andReturn('http://minio:9000/local/tenants/1/dossiers/1/file.pdf?X-Amz-Signature=abc');

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

    $url = (new ResolveDocumentTemporaryUrlAction($filesystems))->handle(
        $uploaded,
        now()->addMinutes(5),
    );

    expect($url)->toBe('http://localhost:9000/local/tenants/1/dossiers/1/file.pdf?X-Amz-Signature=abc');
});

test('resolve document temporary url can request inline disposition for previews', function () {
    config()->set('filesystems.disks.s3.driver', 's3');
    config()->set('filesystems.disks.s3.endpoint', 'http://minio:9000');
    config()->set('filesystems.disks.s3.url', 'http://localhost:9000');

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('temporaryUrl')
        ->once()
        ->withArgs(function (string $path, $expiresAt, array $options): bool {
            return ($options['ResponseContentDisposition'] ?? null) === 'inline; filename="scan.png"'
                && ($options['ResponseContentType'] ?? null) === 'image/png';
        })
        ->andReturn('http://minio:9000/local/tenants/1/dossiers/1/scan.png?X-Amz-Signature=abc');

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

    $url = (new ResolveDocumentTemporaryUrlAction($filesystems))->handle(
        $uploaded,
        now()->addMinutes(5),
        'inline',
    );

    expect($url)->toBe('http://localhost:9000/local/tenants/1/dossiers/1/scan.png?X-Amz-Signature=abc');
});

test('s3 disk reports temporary url support while local does not', function () {
    config()->set('filesystems.disks.s3.driver', 's3');
    config()->set('filesystems.disks.local.driver', 'local');

    $resolver = app(ResolveDocumentTemporaryUrlAction::class);

    expect($resolver->supportsTemporaryUrl(new UploadedDocument(['disk' => 's3'])))->toBeTrue()
        ->and($resolver->supportsTemporaryUrl(new UploadedDocument(['disk' => 'local'])))->toBeFalse();
});

<?php

declare(strict_types=1);

use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\Dossiers\Actions\DocumentRequests\ResolveDocumentTemporaryUrlAction;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Support\Uploads\DocumentPresigner;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

test('local disk downloads stream through the application', function (): void {
    Storage::fake('local');

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

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
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->get(workspaceRoute('workspaces.uploaded-documents.download', $tenant, [
            'uploadedDocument' => $uploaded,
        ]))
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename=payslip.pdf')
        ->assertHeader('x-content-type-options', 'nosniff');

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

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

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
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
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

test('inline previews of client-supplied bytes cannot execute script', function (): void {
    Storage::fake('local');

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

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
    Storage::disk('local')->put($path, '<script>alert(1)</script>');

    // A client-controlled mime type must not turn the preview into an HTML document.
    $uploaded = UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'payslip.pdf',
        'mime_type' => 'text/html',
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->get(workspaceRoute('workspaces.uploaded-documents.preview', $tenant, [
            'uploadedDocument' => $uploaded,
        ]))
        ->assertOk()
        ->assertHeader('x-content-type-options', 'nosniff')
        ->assertHeader('referrer-policy', 'no-referrer')
        ->assertHeader('cross-origin-resource-policy', 'same-origin');

    expect($response->headers->get('content-security-policy'))
        ->toContain("script-src 'none'")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'none'");

    // The app-wide policy must not overwrite the stricter per-file one.
    expect($response->headers->all('content-security-policy'))->toHaveCount(1);
});

test('resolve document temporary url generates a presigned url with attachment disposition', function (): void {
    config()->set('filesystems.disks.s3.driver', 's3');

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('temporaryUrl')
        ->once()
        ->withArgs(fn (string $path, $expiresAt, array $options): bool => $path === 'tenants/1/dossiers/1/file.pdf'
            && ($options['ResponseContentDisposition'] ?? null) === 'attachment; filename=file.pdf'
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

    $url = new ResolveDocumentTemporaryUrlAction($filesystems, new DocumentPresigner($filesystems))->handle(
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
        ->withArgs(fn (string $path, $expiresAt, array $options): bool => ($options['ResponseContentDisposition'] ?? null) === 'inline; filename=scan.png'
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

    $url = new ResolveDocumentTemporaryUrlAction($filesystems, new DocumentPresigner($filesystems))->handle(
        $uploaded,
        now()->addMinutes(5),
        'inline',
    );

    expect($url)->toBe('http://localhost:9000/local/tenants/1/dossiers/1/scan.png?X-Amz-Signature=abc');
});

test('a hostile filename cannot inject a second content-disposition filename', function (string $disposition): void {
    config()->set('filesystems.disks.s3.driver', 's3');

    // Closes the quoted filename early and opens a second parameter —
    // browsers disagree on which "wins", so the UI's payslip.pdf label
    // could save an attacker-named file to disk instead.
    $hostileFilename = 'payslip.pdf"; filename="evil.html';

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('temporaryUrl')
        ->once()
        ->withArgs(function (string $path, $expiresAt, array $options) use ($disposition): bool {
            $value = $options['ResponseContentDisposition'] ?? null;

            // A single, properly quoted filename parameter: the embedded
            // quote and semicolon are backslash-escaped inside the quoted
            // string rather than closing it, so no second parameter can
            // ever be parsed out of this value.
            expect($value)->toBe($disposition.'; filename="payslip.pdf\"; filename=\"evil.html"');

            return true;
        })
        ->andReturn('http://localhost:9000/local/tenants/1/dossiers/1/file.pdf?X-Amz-Signature=abc');

    $filesystems = Mockery::mock(FilesystemManager::class);
    $filesystems->shouldReceive('disk')
        ->once()
        ->with('s3')
        ->andReturn($disk);

    $uploaded = new UploadedDocument([
        'disk' => 's3',
        'path' => 'tenants/1/dossiers/1/file.pdf',
        'original_filename' => $hostileFilename,
        'mime_type' => 'application/pdf',
    ]);

    new ResolveDocumentTemporaryUrlAction($filesystems, new DocumentPresigner($filesystems))->handle($uploaded, now()->addMinutes(5), $disposition);
})->with(['attachment', 'inline']);

test('non-ASCII, comma and semicolon characters produce a single well-formed filename', function (string $disposition): void {
    config()->set('filesystems.disks.s3.driver', 's3');

    $filename = 'café ünïcödé, semi;colon.pdf';

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('temporaryUrl')
        ->once()
        ->withArgs(function (string $path, $expiresAt, array $options) use ($disposition): bool {
            $value = $options['ResponseContentDisposition'] ?? null;

            expect($value)->toBeString()
                ->and($value)->toStartWith($disposition.'; filename="')
                // ASCII-safe fallback for clients that don't read filename*=.
                ->and($value)->toContain('caf_ _n_c_d_, semi;colon.pdf')
                // The real UTF-8 name still reaches modern clients via RFC 5987.
                ->and($value)->toContain("filename*=utf-8''caf%C3%A9");

            return mb_substr_count($value, 'filename=') === 1;
        })
        ->andReturn('http://localhost:9000/local/tenants/1/dossiers/1/file.pdf?X-Amz-Signature=abc');

    $filesystems = Mockery::mock(FilesystemManager::class);
    $filesystems->shouldReceive('disk')
        ->once()
        ->with('s3')
        ->andReturn($disk);

    $uploaded = new UploadedDocument([
        'disk' => 's3',
        'path' => 'tenants/1/dossiers/1/file.pdf',
        'original_filename' => $filename,
        'mime_type' => 'application/pdf',
    ]);

    new ResolveDocumentTemporaryUrlAction($filesystems, new DocumentPresigner($filesystems))->handle($uploaded, now()->addMinutes(5), $disposition);
})->with(['attachment', 'inline']);

test('an excessively long filename is bounded before reaching the content-disposition header', function (): void {
    config()->set('filesystems.disks.s3.driver', 's3');

    $filename = str_repeat('é', 400).'.pdf';

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('temporaryUrl')
        ->once()
        ->withArgs(function (string $path, $expiresAt, array $options): bool {
            $value = $options['ResponseContentDisposition'] ?? null;

            expect($value)->toBeString();

            return mb_strlen($value) < 512;
        })
        ->andReturn('http://localhost:9000/local/tenants/1/dossiers/1/file.pdf?X-Amz-Signature=abc');

    $filesystems = Mockery::mock(FilesystemManager::class);
    $filesystems->shouldReceive('disk')
        ->once()
        ->with('s3')
        ->andReturn($disk);

    $uploaded = new UploadedDocument([
        'disk' => 's3',
        'path' => 'tenants/1/dossiers/1/file.pdf',
        'original_filename' => $filename,
        'mime_type' => 'application/pdf',
    ]);

    new ResolveDocumentTemporaryUrlAction($filesystems, new DocumentPresigner($filesystems))->handle($uploaded, now()->addMinutes(5));
});

test('s3 disk reports temporary url support while local does not', function (): void {
    config()->set('filesystems.disks.s3.driver', 's3');
    config()->set('filesystems.disks.local.driver', 'local');

    $resolver = resolve(ResolveDocumentTemporaryUrlAction::class);

    expect($resolver->supportsTemporaryUrl(new UploadedDocument(['disk' => 's3'])))->toBeTrue()
        ->and($resolver->supportsTemporaryUrl(new UploadedDocument(['disk' => 'local'])))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Failing closed on missing configuration
|--------------------------------------------------------------------------
|
| `disk` is a plain string column written at upload time, so it is data and
| can outlive the disk it names. Config::string() throws on a missing key,
| which turned such a row into a 500 on the download route instead of a
| handled miss; Storage::disk() would then have thrown on the streaming
| fallback anyway, so returning false was not enough on its own.
|
| AWS_BUCKET is the other half: it carries no default, so the config key
| exists as null when unset — and a default passed to Config::string() is
| ignored when the key exists. Only Config::get() plus an explicit check is
| safe (.ai/rules/app.md).
|
*/

test('a document on an unconfigured disk resolves to no driver instead of throwing', function (): void {
    config()->set('filesystems.disks', ['local' => ['driver' => 'local']]);

    $resolver = resolve(ResolveDocumentTemporaryUrlAction::class);
    $orphaned = new UploadedDocument(['disk' => 'retired-archive']);

    expect($resolver->diskDriver($orphaned))->toBeNull()
        ->and($resolver->supportsTemporaryUrl($orphaned))->toBeFalse()
        ->and($resolver->diskDriver(new UploadedDocument(['disk' => 'local'])))->toBe('local');
});

test('downloading a document whose disk was removed is a reported 404, not a 500', function (): void {
    Exceptions::fake();
    Storage::fake('local');

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create(['tenant_id' => $tenant->id, 'client_id' => $client->id]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    $uploaded = UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'disk' => 'retired-archive',
        'path' => 'tenants/'.$tenant->id.'/dossiers/'.$dossier->id.'/file.pdf',
    ]);

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->get(workspaceRoute('workspaces.uploaded-documents.download', $tenant, [
            'uploadedDocument' => $uploaded->id,
        ]))
        ->assertNotFound();

    // A row outliving its disk is still a real problem operators must see.
    Exceptions::assertReported(
        fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'retired-archive'),
    );
});

test('a missing bucket falls through to the adapter instead of throwing', function (): void {
    config()->set('filesystems.disks.s3.driver', 's3');
    // Both set and differing, so the public-presign branch is entered...
    config()->set('filesystems.disks.s3.endpoint', 'http://floci:4566');
    config()->set('filesystems.disks.s3.url', 'http://localhost:4566');
    // ...but AWS_BUCKET is unset, which the config exposes as a present null.
    config()->set('filesystems.disks.s3.bucket');

    // Must be an AwsS3V3Adapter, or the method returns before the bucket is
    // ever read and the test proves nothing.
    $disk = Mockery::mock(AwsS3V3Adapter::class);
    $disk->shouldReceive('getClient')->andReturn(new S3Client([
        'region' => 'eu-west-1',
        'version' => 'latest',
        'endpoint' => 'http://floci:4566',
        'use_path_style_endpoint' => true,
        'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
    ]));
    $disk->shouldReceive('temporaryUrl')->once()->andReturn('http://localhost:4566/signed');

    $filesystems = Mockery::mock(FilesystemManager::class);
    $filesystems->shouldReceive('disk')->once()->with('s3')->andReturn($disk);

    $uploaded = new UploadedDocument([
        'disk' => 's3',
        'path' => 'tenants/1/dossiers/1/file.pdf',
        'original_filename' => 'payslip.pdf',
        'mime_type' => 'application/pdf',
    ]);

    expect(new ResolveDocumentTemporaryUrlAction($filesystems, new DocumentPresigner($filesystems))->handle($uploaded, now()->addMinutes(5)))
        ->toBe('http://localhost:4566/signed');
});

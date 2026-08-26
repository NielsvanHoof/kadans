<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Support\StoredFileResponse;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

covers(StoredFileResponse::class);

/**
 * Stand the s3 disk up as a fake that can sign.
 *
 * DocumentPresigner only builds its own client when the internal and public
 * endpoints differ, so with a fake it returns null and the adapter's own
 * temporaryUrl() is used — which is exactly the path a deployed environment
 * takes, where the two endpoints are identical.
 */
function fakeSignableS3Disk(): void
{
    Storage::fake('s3');
    config()->set('filesystems.disks.s3.driver', 's3');

    Storage::disk('s3')->buildTemporaryUrlsUsing(
        fn (string $path, $expiration, array $options): string => 'https://signed.example/'.$path
            .'?disposition='.rawurlencode((string) ($options['ResponseContentDisposition'] ?? '')),
    );
}

test('an audio recording on s3 is handed off to storage instead of streamed through a worker', function (): void {
    fakeSignableS3Disk();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $recording = AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'disk' => 's3',
        'path' => 'audio/meeting.mp3',
        'original_filename' => 'meeting.mp3',
    ]);

    Storage::disk('s3')->put('audio/meeting.mp3', 'fake-audio-bytes');

    $response = $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => Date::now()->timestamp])
        ->get(workspaceRoute('workspaces.audio-recordings.download', $tenant, [
            'audioRecording' => $recording,
        ]));

    // The redirect is the point: under Octane a streamed response would hold one
    // of a small pool of workers for the whole transfer.
    $response->assertRedirect();

    expect($response->headers->get('location'))
        ->toStartWith('https://signed.example/audio/meeting.mp3')
        // The filename still travels through the hardened builder rather than
        // being interpolated into the header by the new call site.
        ->toContain(rawurlencode('attachment; filename=meeting.mp3'));
});

test('an audio recording on the local disk still streams', function (): void {
    Storage::fake('local');

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $recording = AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'disk' => 'local',
        'path' => 'audio/meeting.mp3',
        'original_filename' => 'meeting.mp3',
    ]);

    Storage::disk('local')->put('audio/meeting.mp3', 'fake-audio-bytes');

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => Date::now()->timestamp])
        ->get(workspaceRoute('workspaces.audio-recordings.download', $tenant, [
            'audioRecording' => $recording,
        ]))
        ->assertOk()
        // The local disk cannot presign, so the hardening headers still matter.
        ->assertHeader('x-content-type-options', 'nosniff');
});

test('a completed dossier export on s3 is handed off to storage', function (): void {
    fakeSignableS3Disk();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Acme Payroll',
    ]);
    $export = DossierExport::factory()
        ->completed('exports/acme.zip')
        ->create([
            'tenant_id' => $tenant->id,
            'dossier_id' => $dossier->id,
            'disk' => 's3',
        ]);

    Storage::disk('s3')->put((string) $export->path, 'zip-bytes');

    // The download route is signed, like every export link the UI hands out.
    $url = URL::temporarySignedRoute(
        'workspaces.dossiers.exports.download',
        now()->addMinutes(5),
        ['tenant' => $tenant, 'dossier' => $dossier, 'export' => $export],
    );

    $response = $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => Date::now()->timestamp])
        ->get($url);

    $response->assertRedirect();

    expect($response->headers->get('location'))
        ->toStartWith('https://signed.example/exports/acme.zip')
        ->toContain(rawurlencode('attachment; filename=acme-payroll-export.zip'));
});

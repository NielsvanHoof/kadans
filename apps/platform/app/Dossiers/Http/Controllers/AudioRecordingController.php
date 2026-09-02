<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Actions\AudioRecordings\DeleteAudioRecordingAction;
use App\Dossiers\Actions\AudioRecordings\UploadAudioRecordingAction;
use App\Dossiers\Actions\GuardUploadedDocumentDeliveryAction;
use App\Dossiers\Actions\Uploads\ClaimUploadTicketAction;
use App\Dossiers\Data\StaffAudioRecordingData;
use App\Dossiers\Enums\UploadPurpose;
use App\Dossiers\Http\Requests\AudioRecordings\StoreAudioRecordingRequest;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Queries\FetchAudioRecordingShowQuery;
use App\Dossiers\Support\StoredFileResponse;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Staff upload + transcript page + audio download.
 */
final class AudioRecordingController extends Controller
{
    /**
     * Response hardening for bytes a staff member uploaded — same rationale as
     * UploadedDocumentController: never let a served file's mime_type drive
     * script execution on this origin.
     */
    private const array UNTRUSTED_FILE_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "script-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        'Referrer-Policy' => 'no-referrer',
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'X-Robots-Tag' => 'noindex, nofollow, noarchive',
    ];

    public function store(
        Tenant $tenant,
        StoreAudioRecordingRequest $request,
        Dossier $dossier,
        UploadAudioRecordingAction $uploadAudioRecording,
        ClaimUploadTicketAction $claimUploadTicket,
    ): RedirectResponse {
        // The recording is already in the bucket; this redeems the ticket
        // naming it and verifies the stored object before anything is written.
        $upload = $claimUploadTicket->handle(
            $request->string('audio')->toString(),
            UploadPurpose::AudioRecording,
            $tenant->id,
            'audio',
        );

        try {
            $uploadAudioRecording->handle($dossier, $upload, $request->user());
        } catch (ModelNotFoundException) {
            // The dossier was deleted between resolving the route and taking
            // its row lock. That is a gone resource, not a bug: reporting it
            // would file a Sentry issue for ordinary concurrency and hide it
            // behind a "try again" the client can never satisfy.
            abort(404);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['audio' => __('Something went wrong while uploading the audio file. Please try again.')]);
        }

        Toast::success(__('Audio recording uploaded.'));

        return to_route(
            'workspaces.dossiers.show',
            $this->workspaceRouteParameters(['dossier' => $dossier]),
        );
    }

    /**
     * Dedicated transcript page. JSON powers the transcript-page island.
     */
    public function show(
        Tenant $tenant,
        Request $request,
        AudioRecording $audioRecording,
        FetchAudioRecordingShowQuery $fetchAudioRecordingShow,
    ): Response|JsonResponse {
        $this->authorize('view', $audioRecording);

        $props = $fetchAudioRecordingShow->handle($audioRecording);

        if ($request->expectsJson()) {
            return response()->json([
                'audio_recording' => $props['audio_recording'],
            ]);
        }

        return Inertia::render('audio-recordings/show', $props);
    }

    /**
     * Narrow status slice for dossier-panel islands (no transcript body).
     */
    public function live(
        Tenant $tenant,
        AudioRecording $audioRecording,
    ): JsonResponse {
        $this->authorize('view', $audioRecording);

        return response()->json([
            'audio_recording' => StaffAudioRecordingData::fromModel($audioRecording)->toArray(),
        ]);
    }

    public function download(
        Tenant $tenant,
        AudioRecording $audioRecording,
        LogAuditActivityAction $logAuditActivity,
        GuardUploadedDocumentDeliveryAction $guardUploadedDocumentDelivery,
        StoredFileResponse $storedFileResponse,
    ): HttpResponse {
        $this->authorize('download', $audioRecording);

        abort_unless(
            $guardUploadedDocumentDelivery->guardStoredFile(
                $audioRecording->disk,
                $audioRecording->path,
                $audioRecording->original_filename,
                $audioRecording,
            ),
            403,
            __('This file hasn\'t passed our security check yet. Try again in a moment.'),
        );

        $logAuditActivity->handle(
            AuditEvent::AudioRecordingDownloaded,
            $audioRecording,
            ['original_filename' => $audioRecording->original_filename],
        );

        return $storedFileResponse->serve(
            $audioRecording->disk,
            $audioRecording->path,
            $audioRecording->original_filename,
            $audioRecording->mime_type,
            'attachment',
            self::UNTRUSTED_FILE_HEADERS,
        );
    }

    public function destroy(
        Tenant $tenant,
        Request $request,
        AudioRecording $audioRecording,
        DeleteAudioRecordingAction $deleteAudioRecording,
    ): RedirectResponse {
        $this->authorize('delete', $audioRecording);

        $dossier = $audioRecording->dossier;

        $deleteAudioRecording->handle($audioRecording, $request->user());

        Toast::success(__('Audio recording removed.'));

        return to_route(
            'workspaces.dossiers.show',
            $this->workspaceRouteParameters(['dossier' => $dossier]),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\AudioRecordings;

use App\Accounts\Models\User;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\AudioRecordingCalendarLink;
use App\Integrations\Actions\ResolveGoogleConnectorAction;
use App\Integrations\Enums\OAuthProvider;
use App\Integrations\Exceptions\OAuthTokenRefreshFailedException;
use App\Integrations\Services\GoogleWorkspaceClient;
use Illuminate\Support\Facades\Date;
use RuntimeException;

use function is_string;

/**
 * Upload a completed transcript and attach it to a calendar event.
 *
 * Only Google is wired today; other providers fail clearly until a second client exists.
 */
final readonly class AttachAudioTranscriptToCalendarEventAction
{
    public function __construct(
        private ResolveGoogleConnectorAction $resolveGoogleConnector,
        private GoogleWorkspaceClient $googleWorkspaceClient,
    ) {}

    /**
     * @throws OAuthTokenRefreshFailedException
     */
    public function handle(
        AudioRecording $audioRecording,
        User $user,
        string $eventId,
        OAuthProvider $provider = OAuthProvider::Google,
    ): AudioRecording {
        throw_if($audioRecording->transcription_status !== TranscriptionStatus::Completed, RuntimeException::class, 'Transcript must be completed before attaching to a calendar event.');

        $transcript = $audioRecording->transcript;

        throw_if(! is_string($transcript) || mb_trim($transcript) === '', RuntimeException::class, 'Transcript text is empty.');

        // Second calendar provider will land here — no fake polymorphism until then.
        if ($provider !== OAuthProvider::Google) {
            throw new RuntimeException(__('Calendar provider is not supported yet.'));
        }

        if (! OAuthProvider::Google->isConfigured()) {
            throw new OAuthTokenRefreshFailedException(
                __('Google sign-in isn\'t set up yet. Contact your administrator.'),
            );
        }

        // Already linked to this event — idempotent success.
        $existingQuery = $audioRecording->calendarLinks()->getQuery();
        $existingQuery->getQuery()
            ->where('provider', $provider)
            ->where('external_event_id', $eventId);
        $existing = $existingQuery->first();

        if ($existing instanceof AudioRecordingCalendarLink) {
            return $audioRecording;
        }

        $connector = $this->resolveGoogleConnector->handle($user);

        $filename = pathinfo($audioRecording->original_filename, PATHINFO_FILENAME);
        $filename = ($filename !== '' ? $filename : 'transcript').'-transcript.txt';

        $result = $this->googleWorkspaceClient->attachTranscriptToEvent(
            $connector,
            $eventId,
            $transcript,
            $filename,
        );

        $audioRecording->calendarLinks()->create([
            'tenant_id' => $audioRecording->tenant_id,
            'provider' => $provider,
            'external_event_id' => $result['event_id'],
            'external_event_url' => $result['html_link'],
            'external_file_id' => $result['drive_file_id'],
            'linked_by_user_id' => $user->id,
            'linked_at' => Date::now(),
        ]);

        return $audioRecording->fresh(['calendarLinks']) ?? $audioRecording;
    }
}

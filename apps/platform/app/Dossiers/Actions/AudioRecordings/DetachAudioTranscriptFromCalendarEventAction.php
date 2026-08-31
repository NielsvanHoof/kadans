<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\AudioRecordings;

use App\Accounts\Models\User;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\AudioRecordingCalendarLink;
use App\Integrations\Actions\ResolveGoogleConnectorAction;
use App\Integrations\Enums\OAuthProvider;
use App\Integrations\Exceptions\OAuthTokenRefreshFailedException;
use App\Integrations\Services\GoogleWorkspaceClient;
use RuntimeException;

/**
 * Reverse a transcript calendar attach: Google event + Drive file, then the local row.
 *
 * Only Google is wired today; other providers fail clearly until a second client exists.
 */
final readonly class DetachAudioTranscriptFromCalendarEventAction
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
        AudioRecordingCalendarLink $calendarLink,
    ): void {
        throw_unless(
            $calendarLink->audio_recording_id === $audioRecording->id,
            RuntimeException::class,
            'Calendar link does not belong to this recording.',
        );

        if ($calendarLink->provider !== OAuthProvider::Google) {
            throw new RuntimeException(__('Calendar provider is not supported yet.'));
        }

        if (! OAuthProvider::Google->isConfigured()) {
            throw new OAuthTokenRefreshFailedException(
                __('Google OAuth is not configured.'),
            );
        }

        $connector = $this->resolveGoogleConnector->handle($user);

        $this->googleWorkspaceClient->detachTranscriptFromEvent(
            $connector,
            $calendarLink->external_event_id,
            $calendarLink->external_file_id,
        );

        $calendarLink->delete();
    }
}

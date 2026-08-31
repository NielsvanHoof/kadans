<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\AudioRecordings;

use App\Accounts\Models\User;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use App\Integrations\Actions\ResolveGoogleConnectorAction;
use App\Integrations\Data\CalendarEventData;
use App\Integrations\Enums\OAuthProvider;
use App\Integrations\Exceptions\OAuthTokenRefreshFailedException;
use App\Integrations\Services\GoogleWorkspaceClient;
use Illuminate\Support\Facades\Date;
use RuntimeException;

/**
 * Suggest primary-calendar events near a recording, ranked by client email match.
 *
 * Only Google is wired today; other providers fail clearly until a second client exists.
 */
final readonly class SuggestCalendarEventsAction
{
    public function __construct(
        private ResolveGoogleConnectorAction $resolveGoogleConnector,
        private GoogleWorkspaceClient $googleWorkspaceClient,
    ) {}

    /**
     * @return list<CalendarEventData>
     *
     * @throws OAuthTokenRefreshFailedException
     */
    public function handle(
        AudioRecording $audioRecording,
        User $user,
        OAuthProvider $provider = OAuthProvider::Google,
    ): array {
        throw_if($audioRecording->transcription_status !== TranscriptionStatus::Completed, RuntimeException::class, 'Transcript must be completed before suggesting calendar events.');

        // Second calendar provider will land here — no fake polymorphism until then.
        if ($provider !== OAuthProvider::Google) {
            throw new RuntimeException(__('Calendar provider is not supported yet.'));
        }

        if (! OAuthProvider::Google->isConfigured()) {
            throw new OAuthTokenRefreshFailedException(
                __('Google OAuth is not configured.'),
            );
        }

        $audioRecording->loadMissing('dossier.client');
        $clientEmail = $audioRecording->dossier?->client?->email;

        $anchor = $audioRecording->created_at ?? Date::now();
        $connector = $this->resolveGoogleConnector->handle($user);

        return $this->googleWorkspaceClient->listPrimaryEvents(
            $connector,
            Date::parse($anchor)->subDays(7),
            Date::parse($anchor)->addDays(7),
            $clientEmail,
        );
    }
}

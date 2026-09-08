<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers\AudioRecordings;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Dossiers\Actions\AudioRecordings\AttachAudioTranscriptToCalendarEventAction;
use App\Dossiers\Actions\AudioRecordings\DetachAudioTranscriptFromCalendarEventAction;
use App\Dossiers\Actions\AudioRecordings\SuggestCalendarEventsAction;
use App\Dossiers\Http\Requests\AudioRecordings\AttachAudioTranscriptToCalendarEventRequest;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\AudioRecordingCalendarLink;
use App\Integrations\Data\Google\CalendarEventData;
use App\Integrations\Exceptions\OAuthTokenRefreshFailedException;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use RuntimeException;
use Saloon\Exceptions\Request\RequestException;

/**
 * Calendar suggest + attach for audio transcripts (Google today).
 */
final class AudioRecordingCalendarController extends Controller
{
    use ResolvesCurrentUser;

    public function suggestions(
        Tenant $tenant,
        AudioRecording $audioRecording,
        SuggestCalendarEventsAction $suggestCalendarEvents,
    ): JsonResponse {
        $this->authorize('view', $audioRecording);

        try {
            $events = $suggestCalendarEvents->handle(
                $audioRecording,
                $this->currentUser(),
            );
        } catch (OAuthTokenRefreshFailedException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'needs_reconsent' => true], 409);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RequestException $exception) {
            report($exception);

            return response()->json(['message' => __('Could not load Google Calendar events. Please try again.')], 502);
        }

        return response()->json([
            'events' => array_map(
                static fn (CalendarEventData $event): array => $event->toArray(),
                $events,
            ),
        ]);
    }

    public function attach(
        Tenant $tenant,
        AttachAudioTranscriptToCalendarEventRequest $request,
        AudioRecording $audioRecording,
        AttachAudioTranscriptToCalendarEventAction $attachAudioTranscriptToCalendarEvent,
    ): RedirectResponse {
        try {
            $attachAudioTranscriptToCalendarEvent->handle(
                $audioRecording,
                $request->authenticatedUser(),
                $request->string('event_id')->toString(),
                $request->calendarProvider(),
            );
        } catch (OAuthTokenRefreshFailedException|RuntimeException $exception) {
            Toast::error($exception->getMessage());

            return back();
        } catch (RequestException $exception) {
            report($exception);
            Toast::error(__('Could not attach the transcript to Google Calendar. Please try again.'));

            return back();
        }

        Toast::success(__('Transcript attached to Google Calendar.'));

        return back();
    }

    public function detach(
        Tenant $tenant,
        AudioRecording $audioRecording,
        AudioRecordingCalendarLink $calendarLink,
        DetachAudioTranscriptFromCalendarEventAction $detachAudioTranscriptFromCalendarEvent,
    ): RedirectResponse {
        $this->authorize('view', $audioRecording);

        abort_unless($calendarLink->audio_recording_id === $audioRecording->id, 404);

        try {
            $detachAudioTranscriptFromCalendarEvent->handle(
                $audioRecording,
                $this->currentUser(),
                $calendarLink,
            );
        } catch (OAuthTokenRefreshFailedException|RuntimeException $exception) {
            Toast::error($exception->getMessage());

            return back();
        } catch (RequestException $exception) {
            report($exception);
            Toast::error(__('Could not unlink the transcript from Google Calendar. Please try again.'));

            return back();
        }

        Toast::success(__('Transcript unlinked from Google Calendar.'));

        return back();
    }
}

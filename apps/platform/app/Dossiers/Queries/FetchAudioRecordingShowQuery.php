<?php

declare(strict_types=1);

namespace App\Dossiers\Queries;

use App\Accounts\Models\User;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\AudioRecordingCalendarLink;
use App\Integrations\Enums\OAuthProvider;
use App\Integrations\Models\OAuthConnection;
use App\Pipelines\Data\PipelineData;

/**
 * Shape the transcript page props for Inertia.
 */
final class FetchAudioRecordingShowQuery
{
    /**
     * @return array{
     *     audio_recording: array{
     *         id: int,
     *         original_filename: string,
     *         size_bytes: int,
     *         uploaded_at: string,
     *         transcription_status: string,
     *         transcription_error: string|null,
     *         transcript: string|null,
     *         segments: array<int, array{text: string, speaker: string, start_seconds: float, end_seconds: float}>|null,
     *         pipeline: array<string, mixed>|null,
     *         calendar_links: list<array{
     *             id: int,
     *             provider: string,
     *             event_id: string,
     *             html_link: string|null,
     *             linked_at: string
     *         }>,
     *     },
     *     dossier: array{id: int, title: string}|null,
     *     can_download: bool,
     *     calendar: array{
     *         provider: string,
     *         connection_status: 'ready'|'missing'|'needs_reconsent'|'unconfigured',
     *         reconnect_url: string|null
     *     }
     * }
     */
    public function handle(AudioRecording $audioRecording): array
    {
        $audioRecording->load(['dossier:id,title,tenant_id', 'latestPipeline.steps', 'calendarLinks']);

        $dossier = $audioRecording->dossier;
        $user = request()->user();
        $latestPipeline = $audioRecording->latestPipeline;

        return [
            'audio_recording' => [
                'id' => $audioRecording->id,
                'original_filename' => $audioRecording->original_filename,
                'size_bytes' => $audioRecording->size_bytes,
                'uploaded_at' => $audioRecording->uploaded_at->toIso8601String(),
                'transcription_status' => $audioRecording->transcription_status->value,
                'transcription_error' => $audioRecording->transcription_error,
                'transcript' => $audioRecording->transcript,
                'segments' => $audioRecording->segments,
                'pipeline' => $latestPipeline === null
                    ? null
                    : PipelineData::fromModel($latestPipeline)->toArray(),
                'calendar_links' => array_values($audioRecording->calendarLinks
                    ->map(fn (AudioRecordingCalendarLink $link): array => [
                        'id' => $link->id,
                        'provider' => $link->provider->value,
                        'event_id' => $link->external_event_id,
                        'html_link' => $link->external_event_url,
                        'linked_at' => $link->linked_at->toIso8601String(),
                    ])
                    ->all()),
            ],
            'dossier' => $dossier === null ? null : [
                'id' => $dossier->id,
                'title' => $dossier->title,
            ],
            'can_download' => $user?->can('download', $audioRecording) ?? false,
            'calendar' => $this->calendarProps($user instanceof User ? $user : null),
        ];
    }

    /**
     * Google is the only calendar connection today; shape stays provider-tagged for later.
     *
     * @return array{provider: string, connection_status: 'ready'|'missing'|'needs_reconsent'|'unconfigured', reconnect_url: string|null}
     */
    private function calendarProps(?User $user): array
    {
        $provider = OAuthProvider::Google;

        if (! $provider->isConfigured()) {
            return [
                'provider' => $provider->value,
                'connection_status' => 'unconfigured',
                'reconnect_url' => null,
            ];
        }

        if (! $user instanceof User) {
            return [
                'provider' => $provider->value,
                'connection_status' => 'missing',
                'reconnect_url' => route('oauth.redirect', [
                    'provider' => $provider->value,
                    'reconsent' => 1,
                ]),
            ];
        }

        $connection = OAuthConnection::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->first();

        if (! $connection instanceof OAuthConnection) {
            return [
                'provider' => $provider->value,
                'connection_status' => 'missing',
                'reconnect_url' => route('oauth.redirect', [
                    'provider' => $provider->value,
                    'reconsent' => 1,
                ]),
            ];
        }

        if (! $provider->hasWorkspaceScopes($connection->scopes)) {
            return [
                'provider' => $provider->value,
                'connection_status' => 'needs_reconsent',
                'reconnect_url' => route('oauth.redirect', [
                    'provider' => $provider->value,
                    'reconsent' => 1,
                ]),
            ];
        }

        return [
            'provider' => $provider->value,
            'connection_status' => 'ready',
            'reconnect_url' => null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\AudioProcessing\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to workspace staff when a recording's transcript is ready.
 *
 * Exists so the notification bell has something narrow to listen to. It used to
 * subscribe to the whole pipeline.updated stream and filter client-side for
 * `audio_transcription` + `completed` — a comment in the hook admitted as much.
 * Because the bell mounts in AppLayout, that made every staff member a
 * subscriber to the firehose on every page, which is what kept pipeline.updated
 * tenant-wide: narrowing its channel was impossible while the bell relied on it
 * reaching everyone.
 *
 * One event per completed transcript, rather than one per pipeline transition.
 */
final class AudioTranscriptionCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Broadcast only after the transcript write commits.
     */
    public bool $afterCommit = true;

    /** Broadcasts share one queue; see PipelineUpdated for why. */
    public string $broadcastQueue = 'broadcasts';

    public function __construct(
        public readonly string $tenantSlug,
        public readonly int $audioRecordingId,
        public readonly ?int $dossierId,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('workspaces.'.$this->tenantSlug),
        ];
    }

    public function broadcastAs(): string
    {
        return 'audio.transcription.completed';
    }

    /**
     * Telescope tags for the queued BroadcastEvent wrapper.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        $tags = [
            'broadcast',
            'audio.transcription.completed',
            'tenant:'.$this->tenantSlug,
            'audio_recording:'.$this->audioRecordingId,
        ];

        if ($this->dossierId !== null) {
            $tags[] = 'dossier:'.$this->dossierId;
        }

        return $tags;
    }

    /**
     * @return array{audio_recording_id: int, dossier_id: int|null}
     */
    public function broadcastWith(): array
    {
        return [
            'audio_recording_id' => $this->audioRecordingId,
            'dossier_id' => $this->dossierId,
        ];
    }
}

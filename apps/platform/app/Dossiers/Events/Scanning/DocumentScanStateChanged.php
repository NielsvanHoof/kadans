<?php

declare(strict_types=1);

namespace App\Dossiers\Events\Scanning;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a stored file's malware verdict arrives.
 *
 * Scanning is asynchronous and usually quick, which is exactly what makes this
 * worth broadcasting: a user who has just uploaded a document is looking at the
 * page while the verdict is in flight, and without this the "scanning…" state
 * would sit there until they reloaded.
 *
 * One event per verdict rather than one per pipeline transition, following
 * AudioTranscriptionCompleted — the document pages do not always know their
 * dossier, so this goes to the workspace channel.
 */
final class DocumentScanStateChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Broadcast only after the verdict write commits. */
    public bool $afterCommit = true;

    /** Broadcasts share one queue; see PipelineUpdated for why. */
    public string $broadcastQueue = 'broadcasts';

    public function __construct(
        public readonly string $tenantSlug,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly string $status,
        public readonly bool $hasThumbnail,
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
        return 'document.scan.state_changed';
    }

    /**
     * Telescope tags for the queued BroadcastEvent wrapper.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return [
            'broadcast',
            'document.scan.state_changed',
            'tenant:'.$this->tenantSlug,
            $this->subjectType.':'.$this->subjectId,
        ];
    }

    /**
     * Carries no filename and no verdict detail beyond the status: the channel
     * is the whole firm, and a client's document name is not something every
     * member needs pushed at them.
     *
     * @return array{subject_type: string, subject_id: int, status: string, has_thumbnail: bool}
     */
    public function broadcastWith(): array
    {
        return [
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'status' => $this->status,
            'has_thumbnail' => $this->hasThumbnail,
        ];
    }
}

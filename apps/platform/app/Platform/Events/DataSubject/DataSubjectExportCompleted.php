<?php

declare(strict_types=1);

namespace App\Platform\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to workspace staff when a queued data-subject export finishes.
 * Payload uses plain values so the queue job does not need Eloquent rehydration.
 */
final class DataSubjectExportCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Broadcast only after the export write transaction commits.
     */
    public bool $afterCommit = true;

    /** Broadcasts share one queue; see PipelineUpdated for why. */
    public string $broadcastQueue = 'broadcasts';

    public function __construct(
        public readonly string $tenantSlug,
        public readonly int $dataSubjectRequestId,
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
        return 'data-subject.export.completed';
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
            'data-subject.export.completed',
            'tenant:'.$this->tenantSlug,
            'data_subject_request:'.$this->dataSubjectRequestId,
        ];
    }

    /**
     * @return array{data_subject_request_id: int}
     */
    public function broadcastWith(): array
    {
        return [
            'data_subject_request_id' => $this->dataSubjectRequestId,
        ];
    }
}

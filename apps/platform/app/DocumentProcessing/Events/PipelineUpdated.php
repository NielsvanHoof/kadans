<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to workspace staff when a pipeline is created or advances.
 * Payload uses plain values so the queue job does not need Eloquent rehydration.
 */
final class PipelineUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Broadcast only after the pipeline write transaction commits.
     */
    public bool $afterCommit = true;

    public function __construct(
        public readonly string $tenantSlug,
        public readonly int $pipelineId,
        public readonly string $type,
        public readonly string $status,
        public readonly string $subjectType,
        public readonly int $subjectId,
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
        return 'pipeline.updated';
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
            'pipeline.updated',
            'tenant:'.$this->tenantSlug,
            'pipeline:'.$this->pipelineId,
            $this->subjectType.':'.$this->subjectId,
        ];

        if ($this->dossierId !== null) {
            $tags[] = 'dossier:'.$this->dossierId;
        }

        return $tags;
    }

    /**
     * @return array{
     *     pipeline_id: int,
     *     type: string,
     *     status: string,
     *     subject_type: string,
     *     subject_id: int,
     *     dossier_id: int|null
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'pipeline_id' => $this->pipelineId,
            'type' => $this->type,
            'status' => $this->status,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'dossier_id' => $this->dossierId,
        ];
    }
}

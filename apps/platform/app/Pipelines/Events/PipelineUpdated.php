<?php

declare(strict_types=1);

namespace App\Pipelines\Events;

use App\Pipelines\Data\PipelineUpdatedPayloadData;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to workspace staff when a pipeline is created or advances.
 * Payload uses plain values so the queue job does not need Eloquent rehydration.
 *
 * Deliberately not on `workspaces.{slug}`. The notification bell mounts in
 * AppLayout, so every staff member is subscribed to that channel on every page
 * — and the Pusher protocol delivers every event on a channel to every
 * subscriber, whether or not a listener is bound. Putting this stream there
 * meant one firm bulk-uploading to a single dossier woke every open tab in the
 * firm, hundreds of times, for events all but one of them discarded.
 *
 * So it goes to the two audiences that actually want it:
 *
 * - `…dossiers.{id}` — the dossier, review, document and recording surfaces,
 *   which each care about one dossier. Subscribers are the people looking at it.
 * - `…pipelines` — the pipeline admin pages, which legitimately want the whole
 *   firm's traffic, plus the two subject pages whose dossier is not always
 *   known. Subscribing costs nothing until someone opens one.
 *
 * A run with no dossier (a purged subject) only reaches the second.
 */
final class PipelineUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Broadcast only after the pipeline write transaction commits.
     */
    public bool $afterCommit = true;

    /**
     * Live-UI events burst — a bulk upload emits hundreds — so they get their
     * own queue rather than sitting in front of interactive work on `default`.
     */
    public string $broadcastQueue = 'broadcasts';

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
     * The firm-wide stream. Named here so the channel authorization route and
     * the frontend hook cannot drift from the broadcaster.
     */
    public static function pipelinesChannel(string $tenantSlug): string
    {
        return 'workspaces.'.$tenantSlug.'.pipelines';
    }

    public static function dossierChannel(string $tenantSlug, int $dossierId): string
    {
        return 'workspaces.'.$tenantSlug.'.dossiers.'.$dossierId;
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel(self::pipelinesChannel($this->tenantSlug)),
        ];

        if ($this->dossierId !== null) {
            $channels[] = new PrivateChannel(self::dossierChannel($this->tenantSlug, $this->dossierId));
        }

        return $channels;
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
     * Shape is owned by PipelineUpdatedPayloadData; see its generated TypeScript.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return new PipelineUpdatedPayloadData(
            pipelineId: $this->pipelineId,
            type: $this->type,
            status: $this->status,
            subjectType: $this->subjectType,
            subjectId: $this->subjectId,
            dossierId: $this->dossierId,
        )->toArray();
    }
}

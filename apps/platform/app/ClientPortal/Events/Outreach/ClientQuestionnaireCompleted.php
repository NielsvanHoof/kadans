<?php

declare(strict_types=1);

namespace App\ClientPortal\Events\Outreach;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to workspace staff when a client finishes every questionnaire item.
 * Payload uses plain values so the queue job does not need Eloquent rehydration.
 */
final class ClientQuestionnaireCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Broadcast only after the portal submission transaction commits.
     */
    public bool $afterCommit = true;

    /** Broadcasts share one queue; see PipelineUpdated for why. */
    public string $broadcastQueue = 'broadcasts';

    public function __construct(
        public readonly string $tenantSlug,
        public readonly int $dossierId,
        public readonly string $dossierTitle,
        public readonly string $clientName,
        public readonly string $message,
        public readonly string $dossierUrl,
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
        return 'client.questionnaire.completed';
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
            'client.questionnaire.completed',
            'tenant:'.$this->tenantSlug,
            'dossier:'.$this->dossierId,
        ];
    }

    /**
     * @return array{
     *     dossier_id: int,
     *     dossier_title: string,
     *     client_name: string,
     *     message: string,
     *     dossier_url: string
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'dossier_id' => $this->dossierId,
            'dossier_title' => $this->dossierTitle,
            'client_name' => $this->clientName,
            'message' => $this->message,
            'dossier_url' => $this->dossierUrl,
        ];
    }
}

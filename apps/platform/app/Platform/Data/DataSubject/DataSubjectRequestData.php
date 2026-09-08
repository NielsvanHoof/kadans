<?php

declare(strict_types=1);

namespace App\Platform\Data\DataSubject;

use App\Clients\Models\Client;
use App\Platform\Models\DataSubjectRequest;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One data-subject request row for the workspace compliance screen.
 */
#[TypeScript]
final class DataSubjectRequestData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $typeLabel,
        public readonly string $status,
        public readonly string $statusLabel,
        public readonly int $clientId,
        public readonly string $clientName,
        public readonly string $requestedAt,
        public readonly string $dueAt,
        public readonly bool $isOverdue,
        public readonly ?string $requestedByName,
        public readonly ?string $completedAt,
        public readonly ?string $completedByName,
        public readonly ?string $refusalReason,
        public readonly bool $canDownload,
    ) {}

    public static function fromModel(DataSubjectRequest $request): self
    {
        $client = $request->client;
        $clientName = $client instanceof Client
            ? $client->name
            : __('Deleted client #:id', ['id' => $request->client_id]);

        return new self(
            id: $request->id,
            type: $request->type->value,
            typeLabel: $request->type->label(),
            status: $request->status->value,
            statusLabel: $request->status->label(),
            clientId: $request->client_id,
            clientName: $clientName,
            requestedAt: $request->requested_at->toIso8601String(),
            dueAt: $request->due_at->toIso8601String(),
            isOverdue: $request->isOverdue(),
            requestedByName: $request->requestedBy?->name,
            completedAt: $request->completed_at?->toIso8601String(),
            completedByName: $request->completedBy?->name,
            refusalReason: $request->refusal_reason,
            canDownload: $request->isDownloadable(),
        );
    }
}

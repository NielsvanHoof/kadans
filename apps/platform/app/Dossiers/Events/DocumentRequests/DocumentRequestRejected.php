<?php

declare(strict_types=1);

namespace App\Dossiers\Events\DocumentRequests;

use App\Accounts\Models\User;
use App\Dossiers\Models\DocumentRequest;

/**
 * Staff rejected a submitted document request and asked the client for changes.
 *
 * Raised inside the review transaction so synchronous listeners run atomically
 * with the status change.
 */
final readonly class DocumentRequestRejected
{
    public function __construct(
        public DocumentRequest $documentRequest,
        public User $reviewer,
    ) {}
}

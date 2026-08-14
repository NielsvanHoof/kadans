<?php

declare(strict_types=1);

use App\Dossiers\Enums\DocumentRequestStatus;

covers(DocumentRequestStatus::class);

test('awaiting-client membership is named on the enum', function (DocumentRequestStatus $status, bool $awaitingClient): void {
    expect($status->isAwaitingClient())->toBe($awaitingClient);
})->with([
    'pending' => [DocumentRequestStatus::Pending, true],
    'submitted' => [DocumentRequestStatus::Submitted, false],
    'accepted' => [DocumentRequestStatus::Accepted, false],
    'rejected' => [DocumentRequestStatus::Rejected, true],
]);

test('awaiting-client lists are derived from matching cases in declaration order', function (): void {
    expect(DocumentRequestStatus::awaitingClient())->toBe([
        DocumentRequestStatus::Pending,
        DocumentRequestStatus::Rejected,
    ])
        ->and(DocumentRequestStatus::awaitingClientValues())->toBe([
            'pending',
            'rejected',
        ]);
});

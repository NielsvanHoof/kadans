<?php

declare(strict_types=1);

use App\Dossiers\Enums\DossierStatus;

covers(DossierStatus::class);

test('locked membership is named on the enum', function (DossierStatus $status, bool $locked): void {
    expect($status->isLocked())->toBe($locked);
})->with([
    'draft' => [DossierStatus::Draft, false],
    'awaiting client' => [DossierStatus::AwaitingClient, false],
    'in review' => [DossierStatus::InReview, false],
    'completed' => [DossierStatus::Completed, true],
]);

test('locked values are derived from matching cases in declaration order', function (): void {
    expect(DossierStatus::lockedValues())->toBe(['completed']);
});

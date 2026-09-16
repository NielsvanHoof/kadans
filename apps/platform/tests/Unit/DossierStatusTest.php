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

test('client visibility is named on the enum', function (DossierStatus $status, bool $shared): void {
    expect($status->isSharedWithClient())->toBe($shared);
})->with([
    // Draft is the portal's security boundary: nothing else is unreachable, and
    // nothing ever moves a dossier back here.
    'draft' => [DossierStatus::Draft, false],
    'awaiting client' => [DossierStatus::AwaitingClient, true],
    'in review' => [DossierStatus::InReview, true],
    // Deliberately still shared. Dropping it out of reach the moment staff
    // complete it would sign a client out mid-questionnaire; canSubmit() is
    // what stops the writes.
    'completed' => [DossierStatus::Completed, true],
]);

test('shared values are derived from matching cases in declaration order', function (): void {
    expect(DossierStatus::sharedWithClientValues())
        ->toBe(['awaiting_client', 'in_review', 'completed']);
});

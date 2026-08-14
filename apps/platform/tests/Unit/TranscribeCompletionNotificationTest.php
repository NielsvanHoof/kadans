<?php

declare(strict_types=1);

use App\AudioProcessing\Data\TranscribeCompletionNotification;

covers(TranscribeCompletionNotification::class);

/**
 * This parsing sat in two places — byte-identical for the job name, and almost
 * identical for the status, where the action upper-cased and the command did
 * not. The command only logged its copy, so nothing failed; the divergence was
 * a trap waiting for the first caller to use it for a decision.
 *
 * These are the cases the two copies disagreed on, plus the untrusted-input
 * cases an unsigned EventBridge envelope makes real.
 */
test('the job id is read from either alias', function (array $notification, ?string $expected): void {
    expect(TranscribeCompletionNotification::fromEventBridgeDetail($notification)->jobId)
        ->toBe($expected);
})->with([
    // AWS sends the long form; the short one is the accepted alias.
    'TranscriptionJobName' => [['TranscriptionJobName' => 'job-1'], 'job-1'],
    'JobName' => [['JobName' => 'job-2'], 'job-2'],
    'JobName wins when both are present' => [
        ['JobName' => 'job-short', 'TranscriptionJobName' => 'job-long'],
        'job-short',
    ],
    'absent' => [[], null],
    'empty string is not a job id' => [['JobName' => ''], null],
    'non-string is ignored' => [['JobName' => 123], null],
]);

test('the status is normalised to upper case whichever alias carried it', function (
    array $notification,
    ?string $expected,
): void {
    expect(TranscribeCompletionNotification::fromEventBridgeDetail($notification)->status)
        ->toBe($expected);
})->with([
    'TranscriptionJobStatus' => [['TranscriptionJobStatus' => 'COMPLETED'], 'COMPLETED'],
    'Status' => [['Status' => 'FAILED'], 'FAILED'],
    // The divergence this class exists to remove: one copy upper-cased, one
    // returned the provider's casing untouched.
    'lower case is normalised' => [['Status' => 'completed'], 'COMPLETED'],
    'mixed case is normalised' => [['TranscriptionJobStatus' => 'Failed'], 'FAILED'],
    'absent' => [[], null],
    'empty string is not a status' => [['Status' => ''], null],
]);

test('terminal states are recognised regardless of the casing the provider sent', function (
    string $status,
    bool $completed,
    bool $failed,
): void {
    $notification = TranscribeCompletionNotification::fromEventBridgeDetail(['Status' => $status]);

    expect($notification->isCompleted())->toBe($completed)
        ->and($notification->isFailed())->toBe($failed);
})->with([
    'COMPLETED' => ['COMPLETED', true, false],
    'SUCCEEDED' => ['SUCCEEDED', true, false],
    'completed lower case' => ['completed', true, false],
    'FAILED' => ['FAILED', false, true],
    'ERROR' => ['ERROR', false, true],
    'error lower case' => ['error', false, true],
    // Transcribe reports IN_PROGRESS too; neither terminal branch may fire.
    'IN_PROGRESS' => ['IN_PROGRESS', false, false],
    'QUEUED' => ['QUEUED', false, false],
]);

test('a missing status is terminal in neither direction', function (): void {
    $notification = TranscribeCompletionNotification::fromEventBridgeDetail([]);

    expect($notification->isCompleted())->toBeFalse()
        ->and($notification->isFailed())->toBeFalse();
});

test('the failure reason falls back to naming the status and is bounded', function (): void {
    $withReason = TranscribeCompletionNotification::fromEventBridgeDetail([
        'Status' => 'FAILED',
        'FailureReason' => 'The media format is not supported.',
    ]);

    $withoutReason = TranscribeCompletionNotification::fromEventBridgeDetail(['Status' => 'FAILED']);

    $overlong = TranscribeCompletionNotification::fromEventBridgeDetail([
        'Status' => 'FAILED',
        'FailureReason' => str_repeat('a', 900),
    ]);

    expect($withReason->failureReason())->toBe('The media format is not supported.')
        // The fallback names what we saw, so the trail is not silent about it.
        ->and($withoutReason->failureReason())->toBe('Amazon Transcribe job reported FAILED.')
        // transcription_error is a 500-char column.
        ->and($overlong->failureReason())->toHaveLength(500);
});

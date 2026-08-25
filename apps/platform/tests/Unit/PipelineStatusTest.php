<?php

declare(strict_types=1);

use App\Pipelines\Enums\PipelineStatus;

covers(PipelineStatus::class);

test('terminal membership stays exhaustive on the enum', function (PipelineStatus $status, bool $terminal): void {
    expect($status->isTerminal())->toBe($terminal);
})->with([
    'pending' => [PipelineStatus::Pending, false],
    'running' => [PipelineStatus::Running, false],
    'completed' => [PipelineStatus::Completed, true],
    'failed' => [PipelineStatus::Failed, true],
    'stuck' => [PipelineStatus::Stuck, true],
    'cancelled' => [PipelineStatus::Cancelled, true],
]);

test('retryable membership is named on the enum', function (PipelineStatus $status, bool $retryable): void {
    expect($status->isRetryable())->toBe($retryable);
})->with([
    'pending' => [PipelineStatus::Pending, false],
    'running' => [PipelineStatus::Running, false],
    'completed' => [PipelineStatus::Completed, false],
    'failed' => [PipelineStatus::Failed, true],
    'stuck' => [PipelineStatus::Stuck, true],
    'cancelled' => [PipelineStatus::Cancelled, true],
]);

test('settled membership is named on the enum', function (PipelineStatus $status, bool $settled): void {
    expect($status->isSettled())->toBe($settled);
})->with([
    'pending' => [PipelineStatus::Pending, false],
    'running' => [PipelineStatus::Running, false],
    'completed' => [PipelineStatus::Completed, true],
    'failed' => [PipelineStatus::Failed, false],
    'stuck' => [PipelineStatus::Stuck, false],
    'cancelled' => [PipelineStatus::Cancelled, false],
]);

test('settled values are derived from matching cases in declaration order', function (): void {
    expect(PipelineStatus::settledValues())->toBe(['completed']);
});

test('retryable values are derived from matching cases in declaration order', function (): void {
    expect(PipelineStatus::retryableValues())->toBe(['failed', 'stuck', 'cancelled']);
});

test('retryable and settled values never overlap', function (): void {
    expect(array_intersect(PipelineStatus::retryableValues(), PipelineStatus::settledValues()))
        ->toBeEmpty();
});

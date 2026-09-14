<?php

declare(strict_types=1);

use App\Platform\Ai\FlociEmbeddings;

covers(FlociEmbeddings::class);

test('local floci stubs titan embeddings', function (): void {
    expect(FlociEmbeddings::shouldStub('local', 'http://floci:4566'))->toBeTrue();
});

test('non-local environments never stub floci embeddings', function (string $environment): void {
    expect(FlociEmbeddings::shouldStub($environment, 'http://floci:4566'))->toBeFalse();
})->with([
    'testing',
    'staging',
    'production',
]);

test('local real bedrock is not stubbed', function (mixed $endpoint): void {
    expect(FlociEmbeddings::shouldStub('local', $endpoint))->toBeFalse();
})->with([
    'null' => null,
    'empty' => '',
    'regional' => 'https://bedrock-runtime.eu-west-1.amazonaws.com',
]);

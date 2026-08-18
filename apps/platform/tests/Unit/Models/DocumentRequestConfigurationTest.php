<?php

declare(strict_types=1);

use App\Dossiers\Models\DocumentRequest;

covers(DocumentRequest::class);

/**
 * `configuration` is a free-form JSON column, so the defaulting is the whole
 * point of these two methods — and the two defaults are opposites, which is
 * exactly the kind of detail that drifts when five call sites each spell it
 * out. The keys are genuinely absent on items that are not File items:
 * NormalizeQuestionnaireItemConfigurationAction only writes allow_multiple for
 * File, so `?? false` is load-bearing rather than defensive.
 */
test('allowsMultipleUploads defaults to false when the key is absent or not exactly true', function (
    ?array $configuration,
    bool $expected,
): void {
    expect(new DocumentRequest(['configuration' => $configuration])->allowsMultipleUploads())
        ->toBe($expected);
})->with([
    'explicitly true' => [['allow_multiple' => true], true],
    'explicitly false' => [['allow_multiple' => false], false],
    'key absent' => [['is_required' => true], false],
    'configuration null' => [null, false],
    // A JSON column can hold anything a past writer put there; only a real
    // boolean true opens a request to several files.
    'truthy but not true' => [['allow_multiple' => 1], false],
    'string' => [['allow_multiple' => 'yes'], false],
]);

test('isRequired defaults to true when the key is absent, the inverse of allow_multiple', function (
    ?array $configuration,
    bool $expected,
): void {
    expect(new DocumentRequest(['configuration' => $configuration])->isRequired())
        ->toBe($expected);
})->with([
    'explicitly true' => [['is_required' => true], true],
    'explicitly false' => [['is_required' => false], false],
    // An item says nothing, so it is required — the opposite default.
    'key absent' => [['allow_multiple' => true], true],
    'configuration null' => [null, true],
    // Only an exact false opts out.
    'falsy but not false' => [['is_required' => 0], true],
]);

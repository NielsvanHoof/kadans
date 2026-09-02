<?php

declare(strict_types=1);

use App\Shared\Support\Localization\TranslationDomains;

/**
 * Proves the split did not drop, rename, or rewrite any key.
 *
 * Committed fixtures are verbatim copies of lang/{en,nl}.json taken before
 * the switchover. Do not derive them from `git show` — CI checkouts have no
 * full history, so that would pass locally and fail in the pipeline.
 *
 * Deleted in the final cleanup commit: a permanent golden would force a
 * third-file edit on every copy change.
 */
test('merged domain files match the pre-split dictionary for every locale', function (string $locale): void {
    $projectRoot = dirname(__DIR__, 3);
    $fixturePath = $projectRoot.'/tests/fixtures/Localization/pre-split/'.$locale.'.json';

    /** @var array<string, string> $fixture */
    $fixture = json_decode(
        (string) file_get_contents($fixturePath),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $merged = [];

    foreach (TranslationDomains::NAMES as $domain) {
        $path = $projectRoot.'/'.TranslationDomains::relativePath($domain, $locale);

        /** @var array<string, string> $chunk */
        $chunk = json_decode(
            (string) file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $merged = [...$merged, ...$chunk];
    }

    ksort($fixture, SORT_STRING);
    ksort($merged, SORT_STRING);

    expect(count($merged))
        ->toBe(count($fixture))
        ->and($merged)
        ->toBe($fixture);
})->with(['en', 'nl']);

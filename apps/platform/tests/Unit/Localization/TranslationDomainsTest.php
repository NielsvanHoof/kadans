<?php

declare(strict_types=1);

use App\Shared\Support\Localization\TranslationDomains;

/**
 * @return array<string, array{raw: string, decoded: array<string, string>}>
 */
function loadDomainTranslationFiles(string $projectRoot, string $locale): array
{
    $files = [];

    foreach (TranslationDomains::NAMES as $domain) {
        $path = $projectRoot.'/'.TranslationDomains::relativePath($domain, $locale);
        $raw = (string) file_get_contents($path);

        /** @var array<string, string> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $files[$domain] = [
            'raw' => $raw,
            'decoded' => $decoded,
        ];
    }

    return $files;
}

/**
 * @param  array<string, array{raw: string, decoded: array<string, string>}>  $files
 * @return array<string, string>
 */
function mergeDecodedDomainFiles(array $files): array
{
    $merged = [];

    foreach ($files as $file) {
        $merged = [...$merged, ...$file['decoded']];
    }

    return $merged;
}

test('no key appears in more than one domain per locale', function (string $locale): void {
    $projectRoot = dirname(__DIR__, 3);
    $files = loadDomainTranslationFiles($projectRoot, $locale);
    $seen = [];

    foreach ($files as $domain => $file) {
        foreach (array_keys($file['decoded']) as $key) {
            // Build the message only after a hit — interpolating $seen[$key]
            // eagerly would warn on every unique key.
            if (array_key_exists($key, $seen)) {
                expect(false)->toBeTrue(
                    "Key [{$key}] appears in both [{$seen[$key]}] and [{$domain}] for locale [{$locale}].",
                );
            }

            $seen[$key] = $domain;
        }
    }

    expect($seen)->not->toBeEmpty();
})->with(['en', 'nl']);

test('every domain has identical key sets in en and nl', function (): void {
    $projectRoot = dirname(__DIR__, 3);
    $english = loadDomainTranslationFiles($projectRoot, 'en');
    $dutch = loadDomainTranslationFiles($projectRoot, 'nl');

    foreach (TranslationDomains::NAMES as $domain) {
        $englishKeys = array_keys($english[$domain]['decoded']);
        $dutchKeys = array_keys($dutch[$domain]['decoded']);

        expect(array_values(array_diff($englishKeys, $dutchKeys)))
            ->toBeEmpty("Domain [{$domain}] has English keys missing from Dutch.")
            ->and(array_values(array_diff($dutchKeys, $englishKeys)))
            ->toBeEmpty("Domain [{$domain}] has Dutch keys missing from English.");
    }
});

test('every domain file is in canonical SORT_STRING form', function (string $locale): void {
    $projectRoot = dirname(__DIR__, 3);
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    foreach (loadDomainTranslationFiles($projectRoot, $locale) as $domain => $file) {
        $decoded = $file['decoded'];
        ksort($decoded, SORT_STRING);
        $canonical = json_encode($decoded, $flags)."\n";

        expect($file['raw'])
            ->toBe($canonical, "Domain [{$domain}] locale [{$locale}] is not in canonical form.");
    }
})->with(['en', 'nl']);

test('raw text has no duplicated keys inside a domain file', function (string $locale): void {
    $projectRoot = dirname(__DIR__, 3);

    foreach (loadDomainTranslationFiles($projectRoot, $locale) as $domain => $file) {
        preg_match_all('/^\s*"((?:\\\\.|[^"\\\\])*)"\s*:/m', $file['raw'], $matches);
        $keys = $matches[1];

        expect(count($keys))
            ->toBe(count(array_unique($keys)), "Domain [{$domain}] locale [{$locale}] has a duplicated raw key line.");
    }
})->with(['en', 'nl']);

test('merged key count matches the expected dictionary size', function (string $locale): void {
    $projectRoot = dirname(__DIR__, 3);
    $files = loadDomainTranslationFiles($projectRoot, $locale);
    $counts = array_map(static fn (array $file): int => count($file['decoded']), $files);

    // 1,500 pre-split keys + "All rights reserved." − five confirmed-dead entries.
    expect(array_sum($counts))->toBe(1496);
})->with(['en', 'nl']);

test('app lang directories on disk match the registered domain list exactly', function (): void {
    $projectRoot = dirname(__DIR__, 3);
    $onDisk = [];

    foreach (scandir($projectRoot.'/app') ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        if (is_dir($projectRoot.'/app/'.$entry.'/'.TranslationDomains::RelativeDirectory)) {
            $onDisk[] = $entry;
        }
    }

    sort($onDisk, SORT_STRING);
    $sortedExpected = TranslationDomains::NAMES;
    sort($sortedExpected, SORT_STRING);

    expect($onDisk)->toBe($sortedExpected);
});

test('lang locale json monolith files do not exist', function (string $locale): void {
    $projectRoot = dirname(__DIR__, 3);

    expect(file_exists($projectRoot.'/lang/'.$locale.'.json'))->toBeFalse(
        "lang/{$locale}.json must not exist: FileLoader merges the base lang path last, so a retained monolith silently wins every collision and the co-located files become inert.",
    );
})->with(['en', 'nl']);

test('every translation value is a string', function (string $locale): void {
    $projectRoot = dirname(__DIR__, 3);

    foreach (loadDomainTranslationFiles($projectRoot, $locale) as $domain => $file) {
        foreach ($file['decoded'] as $key => $value) {
            expect($value)->toBeString(
                "Domain [{$domain}] key [{$key}] decodes to a non-string; integer-looking keys would be reindexed by array_merge.",
            );
        }
    }
})->with(['en', 'nl']);

test('placeholder token multisets match between en and nl', function (): void {
    $projectRoot = dirname(__DIR__, 3);
    $english = mergeDecodedDomainFiles(loadDomainTranslationFiles($projectRoot, 'en'));
    $dutch = mergeDecodedDomainFiles(loadDomainTranslationFiles($projectRoot, 'nl'));

    foreach ($english as $key => $englishValue) {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $englishValue, $englishTokens);
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $dutch[$key] ?? '', $dutchTokens);

        $englishCounts = array_count_values($englishTokens[1]);
        $dutchCounts = array_count_values($dutchTokens[1]);
        ksort($englishCounts, SORT_STRING);
        ksort($dutchCounts, SORT_STRING);

        expect($dutchCounts)
            ->toBe($englishCounts, "Placeholder multiset mismatch for key [{$key}].");
    }
});

<?php

declare(strict_types=1);

use App\Platform\Support\Localization\TranslationCallSiteScanner;
use App\Shared\Support\Localization\TranslationDomains;

/**
 * Merge every domain file for a locale. Deliberately dumb and independent of
 * LoadFrontendTranslationsAction / FileLoader so this remains a second check.
 *
 * @return array{english: array<string, string>, dutch: array<string, string>}
 */
function loadTranslationFiles(string $projectRoot): array
{
    return [
        'english' => mergeDomainTranslationFiles($projectRoot, 'en'),
        'dutch' => mergeDomainTranslationFiles($projectRoot, 'nl'),
    ];
}

/**
 * @return array<string, string>
 */
function mergeDomainTranslationFiles(string $projectRoot, string $locale): array
{
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

    return $merged;
}

/**
 * Thin wrappers over TranslationCallSiteScanner so this test keeps reading as a
 * coverage check. The patterns live in the scanner because `lang:organize`
 * decides where a key belongs from the same call sites — a second
 * implementation here would place keys this test then reports as missing.
 *
 * @return list<string>
 */
function translationKeysUsedInDirectory(string $directory): array
{
    return TranslationCallSiteScanner::flatten(
        new TranslationCallSiteScanner()->phpKeysByFile($directory),
    );
}

/**
 * @return list<string>
 */
function frontendTranslationKeysUsedInDirectory(string $directory): array
{
    return TranslationCallSiteScanner::flatten(
        new TranslationCallSiteScanner()->frontendLiteralKeysByFile($directory),
    );
}

/**
 * @return list<string>
 */
function frontendLabelMapTranslationKeysUsedInDirectory(string $directory): array
{
    return TranslationCallSiteScanner::flatten(
        new TranslationCallSiteScanner()->frontendLabelMapKeysByFile($directory),
    );
}

test('keeps English and Dutch translation keys fully aligned', function (): void {
    $projectRoot = dirname(__DIR__, 2);
    $translations = loadTranslationFiles($projectRoot);

    $englishKeys = array_keys($translations['english']);
    $dutchKeys = array_keys($translations['dutch']);

    // Fail with a clear diff when a key exists in English but not in Dutch.
    $missingInDutch = array_values(array_diff($englishKeys, $dutchKeys));

    // Fail with a clear diff when a key exists in Dutch but not in English.
    $missingInEnglish = array_values(array_diff($dutchKeys, $englishKeys));

    expect($missingInDutch)
        ->toBeEmpty('Missing keys in Dutch domain files: '.json_encode($missingInDutch, JSON_THROW_ON_ERROR))
        ->and($missingInEnglish)
        ->toBeEmpty('Missing keys in English domain files: '.json_encode($missingInEnglish, JSON_THROW_ON_ERROR));
});

test('covers every backend translation key in both locale files', function (): void {
    $projectRoot = dirname(__DIR__, 2);
    $translations = loadTranslationFiles($projectRoot);
    $englishKeys = array_keys($translations['english']);

    $missingInEnglish = array_values(array_diff(
        translationKeysUsedInDirectory($projectRoot.'/app'),
        $englishKeys,
    ));

    expect($missingInEnglish)
        ->toBeEmpty('Missing backend keys in English domain files: '.json_encode($missingInEnglish, JSON_THROW_ON_ERROR));
});

test('covers every frontend translation key in both locale files', function (): void {
    $projectRoot = dirname(__DIR__, 2);
    $translations = loadTranslationFiles($projectRoot);
    $englishKeys = array_keys($translations['english']);
    $dutchKeys = array_keys($translations['dutch']);

    $frontendKeys = array_values(array_unique([
        ...frontendTranslationKeysUsedInDirectory($projectRoot.'/resources/js'),
        ...frontendLabelMapTranslationKeysUsedInDirectory($projectRoot.'/resources/js'),
    ]));

    $missingInEnglish = array_values(array_diff($frontendKeys, $englishKeys));
    $missingInDutch = array_values(array_diff($frontendKeys, $dutchKeys));

    expect($missingInEnglish)
        ->toBeEmpty('Missing frontend keys in English domain files: '.json_encode($missingInEnglish, JSON_THROW_ON_ERROR))
        ->and($missingInDutch)
        ->toBeEmpty('Missing frontend keys in Dutch domain files: '.json_encode($missingInDutch, JSON_THROW_ON_ERROR));
});

test('requires pipeline label-map strings in both locale files', function (): void {
    $projectRoot = dirname(__DIR__, 2);
    $translations = loadTranslationFiles($projectRoot);

    $labelMapKeys = frontendLabelMapTranslationKeysUsedInDirectory(
        $projectRoot.'/resources/js/features/pipelines',
    );

    // Guard the eight strings that previously escaped literal t() scanning.
    $requiredPipelineLabels = [
        'Running',
        'Stuck',
        'Cancelled',
        'Skipped',
        'Submit',
        'Provider processing',
        'Process results',
        'Transcribe',
    ];

    expect($labelMapKeys)
        ->toContain(...$requiredPipelineLabels);

    $missingInEnglish = array_values(array_diff($requiredPipelineLabels, array_keys($translations['english'])));
    $missingInDutch = array_values(array_diff($requiredPipelineLabels, array_keys($translations['dutch'])));

    expect($missingInEnglish)
        ->toBeEmpty('Missing pipeline label keys in English domain files: '.json_encode($missingInEnglish, JSON_THROW_ON_ERROR))
        ->and($missingInDutch)
        ->toBeEmpty('Missing pipeline label keys in Dutch domain files: '.json_encode($missingInDutch, JSON_THROW_ON_ERROR));
});

/**
 * @return list<string>
 */
function flattenValidationKeys(array $lines): array
{
    $keys = [];

    foreach ($lines as $key => $value) {
        if ($key === 'custom') {
            continue;
        }
        if ($key === 'attributes') {
            continue;
        }
        if (is_array($value)) {
            foreach ($value as $nestedKey => $nestedValue) {
                if (is_string($nestedValue)) {
                    $keys[] = "{$key}.{$nestedKey}";
                }
            }

            continue;
        }

        if (is_string($value)) {
            $keys[] = $key;
        }
    }

    sort($keys);

    return $keys;
}

test('keeps English and Dutch validation rule keys aligned', function (): void {
    $projectRoot = dirname(__DIR__, 2);

    /** @var array<string, mixed> $englishValidation */
    $englishValidation = require $projectRoot.'/lang/en/validation.php';

    /** @var array<string, mixed> $dutchValidation */
    $dutchValidation = require $projectRoot.'/lang/nl/validation.php';

    $englishKeys = flattenValidationKeys($englishValidation);
    $dutchKeys = flattenValidationKeys($dutchValidation);

    $missingInDutch = array_values(array_diff($englishKeys, $dutchKeys));
    $missingInEnglish = array_values(array_diff($dutchKeys, $englishKeys));

    expect($missingInDutch)
        ->toBeEmpty('Missing validation keys in nl: '.json_encode($missingInDutch, JSON_THROW_ON_ERROR))
        ->and($missingInEnglish)
        ->toBeEmpty('Missing validation keys in en: '.json_encode($missingInEnglish, JSON_THROW_ON_ERROR));
});

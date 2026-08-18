<?php

declare(strict_types=1);

/**
 * @return array{english: array<string, string>, dutch: array<string, string>}
 */
function loadTranslationFiles(string $projectRoot): array
{
    /** @var array<string, string> $englishTranslations */
    $englishTranslations = json_decode(
        (string) file_get_contents($projectRoot.'/lang/en.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    /** @var array<string, string> $dutchTranslations */
    $dutchTranslations = json_decode(
        (string) file_get_contents($projectRoot.'/lang/nl.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return [
        'english' => $englishTranslations,
        'dutch' => $dutchTranslations,
    ];
}

/**
 * @return list<string>
 */
function translationKeysUsedInDirectory(string $directory): array
{
    $keys = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory),
    );

    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $contents = (string) file_get_contents($file->getPathname());

        if (preg_match_all("/__\\('([^']+)'/", $contents, $matches) !== false) {
            foreach ($matches[1] as $key) {
                $keys[] = $key;
            }
        }
    }

    return array_values(array_unique($keys));
}

/**
 * Extract static t('…') / t("…") keys from frontend TypeScript sources.
 * Skips dynamic calls like t(variable) or t(definition.label).
 *
 * @return list<string>
 */
function frontendTranslationKeysUsedInDirectory(string $directory): array
{
    $keys = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory),
    );

    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $extension = $file->getExtension();

        if ($extension !== 'ts' && $extension !== 'tsx') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        // Match t('key') and t("key"), including keys that span multiple lines.
        if (preg_match_all("/\\bt\\(\\s*(['\"])(.*?)\\1/s", $contents, $matches) !== false) {
            foreach ($matches[2] as $key) {
                // Collapse whitespace from multiline string literals.
                $normalized = preg_replace('/\s+/', ' ', $key);

                if (is_string($normalized) && $normalized !== '') {
                    $keys[] = mb_trim($normalized);
                }
            }
        }
    }

    return array_values(array_unique($keys));
}

/**
 * Extract English strings from frontend label maps that are later passed to t().
 *
 * Catches `_LABELS` / `labels` object literals (e.g. pipeline status modules)
 * that never appear as literal t('…') calls and would otherwise escape coverage.
 *
 * @return list<string>
 */
function frontendLabelMapTranslationKeysUsedInDirectory(string $directory): array
{
    $keys = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory),
    );

    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $extension = $file->getExtension();

        if ($extension !== 'ts' && $extension !== 'tsx') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        // const FOO_LABELS = { … } / export const FOO_LABELS: Record<…> = { … }
        // and inline `const labels: Record<string, string> = { … }`.
        if (preg_match_all(
            '/(?:export\s+)?const\s+(?:\w+_LABELS|labels)\b[^=]*=\s*\{(.*?)\};/s',
            $contents,
            $mapMatches,
        ) === false) {
            continue;
        }

        foreach ($mapMatches[1] as $mapBody) {
            if (preg_match_all("/:\\s*(['\"])(.*?)\\1/s", $mapBody, $valueMatches) === false) {
                continue;
            }

            foreach ($valueMatches[2] as $value) {
                $normalized = preg_replace('/\s+/', ' ', $value);

                if (is_string($normalized) && $normalized !== '') {
                    $keys[] = mb_trim($normalized);
                }
            }
        }
    }

    return array_values(array_unique($keys));
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
        ->toBeEmpty('Missing keys in nl.json: '.json_encode($missingInDutch, JSON_THROW_ON_ERROR))
        ->and($missingInEnglish)
        ->toBeEmpty('Missing keys in en.json: '.json_encode($missingInEnglish, JSON_THROW_ON_ERROR));
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
        ->toBeEmpty('Missing backend keys in en.json: '.json_encode($missingInEnglish, JSON_THROW_ON_ERROR));
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
        ->toBeEmpty('Missing frontend keys in en.json: '.json_encode($missingInEnglish, JSON_THROW_ON_ERROR))
        ->and($missingInDutch)
        ->toBeEmpty('Missing frontend keys in nl.json: '.json_encode($missingInDutch, JSON_THROW_ON_ERROR));
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
        ->toBeEmpty('Missing pipeline label keys in en.json: '.json_encode($missingInEnglish, JSON_THROW_ON_ERROR))
        ->and($missingInDutch)
        ->toBeEmpty('Missing pipeline label keys in nl.json: '.json_encode($missingInDutch, JSON_THROW_ON_ERROR));
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

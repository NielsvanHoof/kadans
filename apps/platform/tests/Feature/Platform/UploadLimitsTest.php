<?php

declare(strict_types=1);

use App\Dossiers\Support\AudioUploadLimits;
use App\Dossiers\Support\DocumentUploadLimits;

/**
 * A validation ceiling above `upload_max_filesize` is the worst kind of drift:
 * PHP fails the file part with UPLOAD_ERR_INI_SIZE while `post_max_size` still
 * admits the body, so there is no 413 and no exception — the FormRequest just
 * fails on `uploaded` and the upload reads as a silent no-op. That is exactly
 * how audio uploads between 20 MB and 25 MB broke (TASK-42), and the CLI SAPI
 * reports the harmless php.ini value, so it does not show up in `artisan`.
 *
 * These parse the real config files, so the ceilings cannot drift again without
 * a red test.
 */

/** php.ini shorthand ("25M", "210M", "512K") to bytes. */
function iniBytes(string $value): int
{
    $value = mb_trim($value);
    $number = (int) $value;

    return match (mb_strtolower(mb_substr($value, -1))) {
        'g' => $number * 1024 ** 3,
        'm' => $number * 1024 ** 2,
        'k' => $number * 1024,
        default => $number,
    };
}

/** Laravel `File::max()` size string ("25mb") to bytes — 1 kb is 1024 bytes. */
function validationBytes(string $value): int
{
    $number = (int) $value;

    return match (mb_strtolower(mb_substr(mb_trim($value), -2))) {
        'gb' => $number * 1024 ** 3,
        'mb' => $number * 1024 ** 2,
        'kb' => $number * 1024,
        default => $number,
    };
}

/**
 * @return array{upload_max_filesize: int, post_max_size: int}
 */
function phpUploadCeilings(string $configPath): array
{
    $contents = (string) file_get_contents(base_path($configPath));
    $ceilings = [];

    foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
        // Matches both `key=25M` (php.ini) and `php_ini key 25M` (Caddyfile).
        expect((bool) preg_match('/'.$directive.'\s*[= ]\s*(\d+[KMG]?)/i', $contents, $matches))
            ->toBeTrue("{$configPath} does not set {$directive}");

        $ceilings[$directive] = iniBytes($matches[1]);
    }

    /** @var array{upload_max_filesize: int, post_max_size: int} */
    return $ceilings;
}

test('every environment admits files as large as the app says it accepts', function (string $configPath): void {
    $ceilings = phpUploadCeilings($configPath);

    $largestValidated = max(
        validationBytes(AudioUploadLimits::MAX_FILE_SIZE),
        validationBytes(DocumentUploadLimits::MAX_FILE_SIZE),
    );

    expect($ceilings['upload_max_filesize'])->toBeGreaterThanOrEqual($largestValidated);
})->with([
    // The Caddyfile's php_ini directive wins over docker/8.5/php.ini for the
    // FrankenPHP web SAPI, which is why it is the one that has to be checked.
    'local (Sail / FrankenPHP)' => 'docker/8.5/Caddyfile',
    'staging' => 'docker/staging/php.ini',
    'production' => 'docker/production/php.ini',
]);

test('every environment admits a full multi-file document post', function (string $configPath): void {
    $ceilings = phpUploadCeilings($configPath);

    $largestPost = validationBytes(DocumentUploadLimits::MAX_FILE_SIZE)
        * DocumentUploadLimits::MAX_FILES_WHEN_MULTIPLE;

    expect($ceilings['post_max_size'])->toBeGreaterThanOrEqual($largestPost)
        // A post_max_size at or below upload_max_filesize would discard the whole
        // body first, turning a per-field error into a blanket 413.
        ->and($ceilings['post_max_size'])->toBeGreaterThan($ceilings['upload_max_filesize']);
})->with([
    'local (Sail / FrankenPHP)' => 'docker/8.5/Caddyfile',
    'staging' => 'docker/staging/php.ini',
    'production' => 'docker/production/php.ini',
]);

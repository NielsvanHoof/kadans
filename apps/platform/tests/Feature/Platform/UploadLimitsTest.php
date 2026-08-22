<?php

declare(strict_types=1);

use App\Dossiers\Enums\UploadPurpose;
use App\Dossiers\Support\AudioUploadLimits;
use App\Dossiers\Support\DocumentUploadLimits;

/**
 * The dropzones and the server must agree on what they accept.
 *
 * This file used to compare the validation ceilings against php.ini's
 * `upload_max_filesize`, because a validation limit above the ini limit was the
 * worst kind of drift: PHP dropped the file part with UPLOAD_ERR_INI_SIZE while
 * `post_max_size` still admitted the body, so there was no 413 and no
 * exception — the upload simply read as a silent no-op (TASK-42).
 *
 * Presigned direct-to-S3 uploads removed that failure mode entirely: the bytes
 * never pass through PHP, so no ini directive can truncate them. What replaced
 * it is a subtler version of the same drift. The browser checks a file against
 * a constant in the dropzone; the server checks the stored object against a
 * constant in ClaimUploadTicketAction. If the first is larger, the user waits
 * out a full 25 MB upload only to be told afterwards that it was too big — and
 * an orphan is left in the staging prefix for the lifecycle rule to sweep.
 *
 * So this parses the real TypeScript and asserts the two sides match.
 */
covers(UploadPurpose::class);

/** Read a `const NAME = <expr>;` numeric literal out of a TypeScript file. */
function typeScriptConstant(string $path, string $name): int
{
    $contents = (string) file_get_contents(base_path($path));

    expect(preg_match('/'.preg_quote($name, '/').'\s*=\s*([0-9*\s]+);/', $contents, $matches))
        ->toBeTruthy("{$path} does not define {$name}");

    // The constants are written as `20 * 1024 * 1024`, which is the readable
    // form and the one worth keeping; multiply the factors out rather than
    // asking the source to spell a byte count.
    return array_product(array_map(
        static fn (string $factor): int => (int) mb_trim($factor),
        explode('*', $matches[1]),
    ));
}

/** Read a single-quoted `const NAME = '...';` string out of a TypeScript file. */
function typeScriptString(string $path, string $name): string
{
    $contents = (string) file_get_contents(base_path($path));

    expect(preg_match('/'.preg_quote($name, '/').'\s*=\s*\n?\s*\'([^\']*)\'/', $contents, $matches))
        ->toBeTruthy("{$path} does not define {$name}");

    return $matches[1];
}

test('the dropzone size ceilings match the server ceilings', function (
    string $path,
    string $constant,
    int $serverBytes,
): void {
    expect(typeScriptConstant($path, $constant))->toBe($serverBytes);
})->with([
    'documents' => [
        'resources/js/components/forms/file-dropzone.tsx',
        'DEFAULT_MAX_FILE_BYTES',
        DocumentUploadLimits::MAX_FILE_BYTES,
    ],
    'audio recordings' => [
        'resources/js/features/audio-recordings/audio-recording-upload-form.tsx',
        'AUDIO_MAX_BYTES',
        AudioUploadLimits::MAX_FILE_BYTES,
    ],
]);

test('every extension the dropzone offers is one the server will claim', function (
    string $path,
    string $constant,
    UploadPurpose $purpose,
): void {
    $offered = array_values(array_filter(
        array_map(
            static fn (string $token): string => mb_ltrim(mb_strtolower(mb_trim($token)), '.'),
            explode(',', typeScriptString($path, $constant)),
        ),
        // The audio dropzone also lists MIME types (audio/mpeg); only the
        // dotted extensions are what the claim check compares against.
        static fn (string $token): bool => $token !== '' && ! str_contains($token, '/'),
    ));

    expect($offered)->not->toBeEmpty()
        ->and(array_diff($offered, $purpose->allowedExtensions()))->toBeEmpty();
})->with([
    'documents' => [
        'resources/js/components/forms/file-dropzone.tsx',
        'DEFAULT_FILE_ACCEPT',
        UploadPurpose::Document,
    ],
    'audio recordings' => [
        'resources/js/features/audio-recordings/audio-recording-upload-form.tsx',
        'AUDIO_ACCEPT',
        UploadPurpose::AudioRecording,
    ],
]);

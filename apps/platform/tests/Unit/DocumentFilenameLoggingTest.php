<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Client filenames stay out of the application log
|--------------------------------------------------------------------------
|
| Dutch client filenames are personal data on their own:
| "Loonstrook_J_de_Vries_maart.pdf" names the person, the document type and
| the period without anyone opening the file.
|
| The audit trail is allowed to record them, and does — that trail is
| tenant-scoped, readable in the workspace UI, deleted by
| retention:purge-legal, and deleted again by AnonymizeDataSubjectAction. The
| application log stack is none of those things. It has its own retention and
| its own access, and nothing purges it, so a filename that reaches Log::
| context can no longer be erased on request. docs/security.md promises the
| client's trail is removed; before TASK-36.4 that promise was not true.
|
| Four orchestrator/job call sites and two mock drivers put
| original_filename into Log:: context, beside correct logAuditActivity
| calls. A comment on each would not have held: the audit call sits three
| lines below the log call and looks almost identical, so the next person
| adding a log line copies the wrong neighbour. The rule is enforced here
| instead, the same way tests/Unit/DossierLockOrderTest.php enforces lock
| order — by scanning source.
|
| The scanner is deliberately proven before it is trusted: a regex that
| silently matches nothing would make every assertion below pass while the
| invariant it guards is broken.
|
*/

/** Attribute names that identify a row without naming a person. */
const PERMITTED_LOG_IDENTITY_KEYS = ['id', 'tenant_id'];

/** Model attributes carrying client-supplied text, which logs must not repeat. */
const FORBIDDEN_LOG_CONTEXT_KEYS = ['original_filename'];

/**
 * Every application PHP file, as paths relative to the project root.
 *
 * @return list<string>
 */
function applicationFilesForLogScan(): array
{
    $root = dirname(__DIR__, 2);
    $paths = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $paths[] = str_replace($root.'/', '', (string) $file->getPathname());
        }
    }

    sort($paths);

    return $paths;
}

/**
 * The context-array keys of every Log:: call in a file.
 *
 * Log:: calls are found by name and their context array is read to the
 * matching close bracket, so a key on any line of a multi-line array is seen.
 * Only `Log::` is matched: logAuditActivity() and activity() calls write to
 * the audit trail, which is allowed to name the file and is purged with it.
 *
 * @return list<string>
 */
function loggedContextKeysIn(string $relativePath): array
{
    return loggedContextKeysInSource(
        (string) file_get_contents(dirname(__DIR__, 2).'/'.$relativePath),
    );
}

/**
 * The context-array keys of every Log:: call in a source string.
 *
 * Split from the file reader so the scanner can be proven against a fixture
 * without writing one to disk.
 *
 * @return list<string>
 */
function loggedContextKeysInSource(string $source): array
{
    /** @var list<string> $keys */
    $keys = [];

    // Match the opening of a Log:: call's context array, then walk forward
    // counting brackets so nested arrays do not end the scan early.
    preg_match_all('/Log::\w+\([^,]*,\s*\[/', $source, $matches, PREG_OFFSET_CAPTURE);

    foreach ($matches[0] as $match) {
        $offset = (int) $match[1] + mb_strlen((string) $match[0]) - 1;
        $depth = 0;
        $length = mb_strlen($source);
        $end = $offset;

        for ($cursor = $offset; $cursor < $length; $cursor++) {
            $character = $source[$cursor];

            if ($character === '[') {
                $depth++;
            }

            if ($character === ']') {
                $depth--;

                if ($depth === 0) {
                    $end = $cursor;

                    break;
                }
            }
        }

        $context = mb_substr($source, $offset, $end - $offset + 1);

        preg_match_all("/'(?<key>[A-Za-z_][A-Za-z0-9_]*)'\s*=>/", $context, $contextKeys);

        foreach ($contextKeys['key'] as $key) {
            $keys[] = $key;
        }
    }

    return $keys;
}

test('the log scanner reads the context keys it is meant to police', function (): void {
    // Prove the scanner sees real Log:: context before trusting it to report
    // an absence, and prove it reads past the first line of the array.
    $orchestratorKeys = loggedContextKeysIn('app/DocumentProcessing/Services/Ocr/OcrOrchestrator.php');

    expect($orchestratorKeys)
        ->toContain('uploaded_document_id')
        ->toContain('tenant_id')
        // Read from a later line of a multi-line context array.
        ->toContain('processing_status')
        ->toContain('outcome');

    // And prove it would actually catch a forbidden key, using a fixture whose
    // shape matches the call sites this test guards.
    $fixture = <<<'PHP'
        Log::info('OCR: something happened.', [
            'uploaded_document_id' => $document->id,
            'original_filename' => $document->original_filename,
            'nested' => ['inner' => 1],
        ]);
        PHP;

    expect(loggedContextKeysInSource($fixture))->toContain('original_filename', 'inner');
});

test('no application log call puts a client-supplied filename in its context', function (): void {
    /** @var array<string, list<string>> $violations */
    $violations = [];

    foreach (applicationFilesForLogScan() as $path) {
        $forbidden = array_values(array_intersect(
            loggedContextKeysIn($path),
            FORBIDDEN_LOG_CONTEXT_KEYS,
        ));

        if ($forbidden !== []) {
            $violations[$path] = $forbidden;
        }
    }

    expect($violations)->toBe(
        [],
        'These Log:: calls put client-supplied filenames into the application '
        .'log, which has different retention and access than the audit trail '
        .'and is reached by neither retention:purge-legal nor '
        .'AnonymizeDataSubjectAction — so an erasure request cannot be fully '
        .'honoured. Log '.implode(' / ', PERMITTED_LOG_IDENTITY_KEYS).' instead '
        .'and let the logAuditActivity call carry the filename.',
    );
});

test('the OCR and transcription log calls still identify their row', function (string $path, string $idKey): void {
    // Removing the filename must not leave a log line nobody can correlate.
    expect(loggedContextKeysIn($path))->toContain($idKey, 'tenant_id');
})->with([
    'ocr orchestrator' => ['app/DocumentProcessing/Services/Ocr/OcrOrchestrator.php', 'uploaded_document_id'],
    'document job' => ['app/DocumentProcessing/Jobs/Ocr/ProcessUploadedDocumentJob.php', 'uploaded_document_id'],
    'transcription orchestrator' => ['app/AudioProcessing/Services/TranscriptionOrchestrator.php', 'audio_recording_id'],
    'audio job' => ['app/AudioProcessing/Jobs/ProcessAudioRecordingJob.php', 'audio_recording_id'],
]);

<?php

declare(strict_types=1);

use FilesystemIterator as Fs;

/*
|--------------------------------------------------------------------------
| Serialized timestamp format
|--------------------------------------------------------------------------
|
| Timestamps that cross the wire to the frontend must carry a UTC offset.
|
| `toDateTimeString()` emits "2026-08-14 11:21:54" with no offset, and
| `new Date()` in V8 parses an offset-less string as LOCAL time. A UTC instant
| therefore arrives in the browser shifted by the viewer's own offset — which is
| how a notification eight minutes old rendered as "2 uur geleden" for a CEST
| user while the absolute timestamp beside it looked correct.
|
| The bug is invisible in tests that hand-write their fixtures: the notification
| Vitest fixtures used a proper `...Z` string, so they exercised a format the
| backend never actually produced. Only asserting against the serializer's own
| output catches it, and only a scan catches the seventh call site.
|
| `toIso8601String()` is the house default (70+ call sites). `toDateString()` is
| correct and unaffected for calendar days (`due_date`), which are deliberately
| offset-free — see resources/js/lib/format-date-time.ts.
|
| If a genuine non-boundary need for `toDateTimeString()` ever appears, add the
| path here with the reason. Keep this a contract, not a changelog.
|
*/

/** @var list<string> */
$allowedPaths = [];

test('no wire-bound timestamp is serialized without an offset', function () use ($allowedPaths): void {
    $root = dirname(__DIR__, 2);
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/app', Fs::SKIP_DOTS),
    );

    $offenders = [];

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relativePath = str_replace($root.'/', '', $file->getPathname());

        if (in_array($relativePath, $allowedPaths, true)) {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getPathname()), 'toDateTimeString(')) {
            $offenders[] = $relativePath;
        }
    }

    expect($offenders)->toBe(
        [],
        'Use toIso8601String() so the browser cannot parse the instant as local time.',
    );
});

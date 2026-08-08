<?php

declare(strict_types=1);

use App\Audit\Enums\AuditEvent;

/**
 * AuditEvent cases are cheap to add and easy to forget to emit. Three of them
 * (DocumentViewed, AccessDenied, AudioRecordingUploaded) sat defined, translated,
 * and surfaced in the activity UI while writing no rows at all — a silent hole in
 * the trail that only a manual review caught. This keeps that failure loud.
 */
test('every audit event is emitted somewhere in the application', function (): void {
    $root = dirname(__DIR__, 2);
    $enumPath = $root.'/app/Audit/Enums/AuditEvent.php';

    $sources = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        // The enum defines every case; only emissions elsewhere count.
        if ($file->getPathname() === $enumPath) {
            continue;
        }

        $sources[] = (string) file_get_contents($file->getPathname());
    }

    // bootstrap/app.php wires the AccessDenied render hook and lives outside app/.
    $sources[] = (string) file_get_contents($root.'/bootstrap/app.php');

    $application = implode("\n", $sources);

    $unemitted = array_values(array_filter(
        AuditEvent::cases(),
        static fn (AuditEvent $event): bool => ! str_contains(
            $application,
            'AuditEvent::'.$event->name,
        ),
    ));

    expect(array_map(static fn (AuditEvent $event): string => $event->name, $unemitted))
        ->toBe([], 'Every AuditEvent case must be emitted, or removed from the enum.');
});

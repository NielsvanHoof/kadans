<?php

declare(strict_types=1);

use App\Audit\Enums\AuditEvent;
use Symfony\Component\Finder\Finder;

/**
 * `AuditEvent` is the audit trail's contract with the firms using this platform:
 * docs/security.md promises typed events on view, download, export, and access
 * denial. A case that is declared and labelled but never emitted, or emitted but
 * never proven, makes that promise partly fiction — and the gap is invisible,
 * because nothing fails when an event simply never fires.
 *
 * These two tests close that off from both ends.
 *
 * @param  list<string>  $excludedFiles
 * @return list<string>
 */
function auditEventNamesReferencedIn(string $directory, array $excludedFiles = []): array
{
    $referenced = [];

    foreach (Finder::create()->files()->in($directory)->name('*.php') as $file) {
        if (in_array($file->getFilename(), $excludedFiles, true)) {
            continue;
        }

        preg_match_all(
            '/AuditEvent::([A-Za-z][A-Za-z0-9_]*)/',
            (string) file_get_contents($file->getRealPath()),
            $matches,
        );

        foreach ($matches[1] as $name) {
            $referenced[$name] = true;
        }
    }

    return array_keys($referenced);
}

/** @return list<string> */
function declaredAuditEventNames(): array
{
    return array_map(static fn (AuditEvent $event): string => $event->name, AuditEvent::cases());
}

/**
 * Catches a case added to the enum (with a label, so `label()`'s match stays
 * exhaustive and nothing breaks) that was never wired to an emitter.
 *
 * The enum declares the cases and the Reporting read side only displays and
 * filters them, so neither counts as evidence that anything writes the event.
 */
test('every audit event is emitted by application code', function (): void {
    $unemitted = array_values(array_diff(
        declaredAuditEventNames(),
        auditEventNamesReferencedIn(app_path(), [
            'AuditEvent.php',
            'ActivityLogRowMapper.php',
            'FetchActivityLogIndexQuery.php',
        ]),
    ));

    expect($unemitted)->toBe([], sprintf(
        'These audit events are declared but nothing writes them: %s',
        implode(', ', $unemitted),
    ));
});

/**
 * Emission alone is not evidence. An event fired from a rarely-taken branch —
 * a failed export, an expired support session — can silently stop firing and no
 * test would notice, which is exactly when an audit trail matters most.
 */
test('every audit event is covered by a test', function (): void {
    $untested = array_values(array_diff(
        declaredAuditEventNames(),
        auditEventNamesReferencedIn(base_path('tests')),
    ));

    expect($untested)->toBe([], sprintf(
        'These audit events have no test asserting they are recorded: %s',
        implode(', ', $untested),
    ));
});

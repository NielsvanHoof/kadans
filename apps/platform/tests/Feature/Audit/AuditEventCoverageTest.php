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
 * Emission alone is not evidence. An event fired from a rarely-taken branch —
 * a failed export, an expired support session — can silently stop firing and no
 * test would notice, which is exactly when an audit trail matters most.
 *
 * The other half of this contract — that every case is emitted *somewhere* in
 * the application — is already covered by tests/Unit/AuditEventCoverageTest.php,
 * which also scans bootstrap/app.php for the AccessDenied render hook. Do not
 * duplicate it here.
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

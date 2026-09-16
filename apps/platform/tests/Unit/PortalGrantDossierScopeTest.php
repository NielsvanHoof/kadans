<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Portal grant scope
|--------------------------------------------------------------------------
|
| Which dossiers a magic link opens has exactly one definition:
| FetchReachableDossiersQuery, reached through ResolveGrantedDossierQuery.
|
| It did not always. The comparison `$documentRequest->dossier_id ===
| $grant->dossier_id` lived in eight places — two of them inside a row lock —
| and each was the portal's entire authorization boundary at that point. That
| was survivable while a grant meant one dossier. It stopped being survivable
| the moment one link could open several, because reach became a live query
| that no equality check can stand in for.
|
| An arch() rule cannot express this. Pest's arch plugin reasons about which
| classes a file depends on, and every file here legitimately depends on
| ClientAccessGrant — the violation is a property read, which is invisible to
| it. So the source is scanned instead, in two parts:
|
|   1. Nothing outside a small, named allowlist reads dossier_id off a grant.
|   2. Anything in the portal's HTTP or submission layer that touches a grant
|      also reaches for the chokepoint. This is the half that catches a NEW
|      controller which simply forgets to check — something a negative regex
|      can never see.
|
*/

/**
 * Files allowed to read a grant's dossier_id, and why.
 *
 * Keep this short. Every entry is a place where the rule above is suspended,
 * so each one needs a reason that survives being read by someone else.
 */
const GRANT_DOSSIER_ID_ALLOWLIST = [
    // Defines the column.
    'Models/ClientAccessGrant.php',
    // The one definition of reach; branches on scope to build the query.
    'Queries/Access/FetchReachableDossiersQuery.php',
    // Sends a dossier-scoped link back to the dossier it belongs to.
    'Http/Controllers/Access/ClientAccessGrantController.php',
    // Reminder bookkeeping, which is per dossier by definition and returns
    // early for a link that names none.
    'Listeners/Outreach/LogClientPortalReminderSent.php',
    'Listeners/Outreach/LogClientPortalReminderFailed.php',
    // Audit metadata: records which dossier a grant named, if any.
    'Actions/Access/RevokeClientPortalAccessAction.php',
    'Actions/Session/RecordClientPortalAccessAction.php',
    'Actions/Session/RegisterClientPortalDeviceAction.php',
    'Listeners/Outreach/LogClientPortalInviteSent.php',
    'Listeners/Outreach/LogClientPortalInviteFailed.php',
];

/**
 * Source with comments removed.
 *
 * The rule is documented in prose in several of these files, and a docblock
 * explaining what not to write must not read as writing it.
 */
function stripPhpComments(string $contents): string
{
    $stripped = '';

    foreach (token_get_all($contents) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $stripped .= is_array($token) ? $token[1] : $token;
    }

    return $stripped;
}

/**
 * @return array<string, string> relative path => contents
 */
function clientPortalSources(string $subPath = ''): array
{
    // dirname(__DIR__, 2), not app_path(): tests/Unit runs as pure PHP with no
    // application booted, the same way DossierLockOrderTest reads source.
    $root = dirname(__DIR__, 2).'/app/ClientPortal';

    $files = [];

    foreach (Finder::create()->files()->in($root.$subPath)->name('*.php') as $file) {
        $files[str_replace($root.'/', '', $file->getRealPath())] = $file->getContents();
    }

    return $files;
}

test('only the reachability query reads a grant dossier id', function (): void {
    $offenders = [];

    foreach (clientPortalSources() as $relative => $contents) {
        if (in_array($relative, GRANT_DOSSIER_ID_ALLOWLIST, true)) {
            continue;
        }

        if (preg_match('/(\$grant|\$lockedGrant)\s*->\s*dossier_id/', stripPhpComments($contents)) === 1) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([], implode(', ', $offenders).' reads dossier_id off a grant directly. '
        .'Ask ResolveGrantedDossierQuery instead, or add the file to GRANT_DOSSIER_ID_ALLOWLIST with a reason.');
});

test('every portal endpoint that handles a grant goes through the chokepoint', function (): void {
    $offenders = [];

    foreach (['/Http/Controllers', '/Http/Requests', '/Actions/Submissions'] as $subPath) {
        foreach (clientPortalSources($subPath) as $relative => $contents) {
            if (! str_contains($contents, 'ClientAccessGrant')) {
                continue;
            }

            // Staff-facing endpoints manage grants rather than act through one;
            // they authorize with the Gate, which AuthorizationCoverageTest
            // checks separately.
            if (str_contains($relative, 'Access/')) {
                continue;
            }

            // Exchanging a token opens a session; it decides nothing about
            // dossiers. The form request only checks a grant is present, and
            // its controller runs the chokepoint on the dossier_id it validates.
            if (in_array($relative, [
                'Http/Controllers/Session/ClientPortalSessionController.php',
                'Http/Requests/Submissions/StorePortalUploadTicketRequest.php',
            ], true)) {
                continue;
            }

            if (! str_contains($contents, 'ResolveGrantedDossierQuery')
                && ! str_contains($contents, 'FetchReachableDossiersQuery')) {
                $offenders[] = $relative;
            }
        }
    }

    expect($offenders)->toBe([], implode(', ', $offenders).' accepts a portal grant without asking '
        .'which dossiers it opens. Every client-facing endpoint must resolve the dossier through the chokepoint.');
});

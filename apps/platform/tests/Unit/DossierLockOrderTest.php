<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Dossier lock order
|--------------------------------------------------------------------------
|
| One canonical order for row locks, parent before child:
|
|     dossiers -> document_requests -> client_access_grants
|
| Staff completing a dossier locked dossier-then-item; a client submitting the
| last answer locked item-then-dossier. Run them at the same time and Postgres
| breaks the cycle by killing one (SQLSTATE 40P01), so whoever lost got a 500.
|
| A comment could not have prevented that. Two of the four violators did not
| lock the dossier in their own file at all — they locked it inside a callback
| that UploadDocumentForRequestAction invoked *after* it had taken the item
| lock. Both files read as dossier-first in isolation. So the order is enforced
| here instead, in two parts:
|
|   1. Within a file, ranked locks only ever go in canonical order.
|   2. Only DossierLocks may lock dossiers or document_requests at all, which
|      is what closes the callback hole: with every lock behind one class, no
|      caller can interleave them.
|
| Runtime ordering is covered separately in
| tests/Feature/Dossiers/DossierLockOrderTest.php, which asserts the queries
| really are emitted dossier-first — including across that callback.
|
*/

/**
 * Canonical rank per locked model. Unranked models are ignored: they are not
 * part of this cycle, and ranking every table would turn an invariant that
 * matters into a chore nobody keeps up to date.
 */
const CANONICAL_LOCK_RANK = [
    'Dossier' => 1,
    'DocumentRequest' => 2,
    'ClientAccessGrant' => 3,
];

/** The only class allowed to take a dossiers or document_requests row lock. */
const LOCK_OWNER = 'app/Dossiers/Support/DossierLocks.php';

/**
 * A model in a static call, imported or fully qualified.
 *
 * The namespace prefix is not optional politeness: without it the scanner reads
 * `\App\Dossiers\Models\Dossier::query()` as an unrecognised expression and the
 * lock becomes invisible, which is exactly the kind of silent gap that lets a
 * guard test go green while the invariant it guards is broken.
 */
const MODEL_REFERENCE = '(?:\\\\?[A-Za-z_]\w*\\\\)*(?<model>\w+)';

/** Locks that DossierLocks takes on its callers' behalf, in the order it takes them. */
const DOSSIER_LOCKS_METHODS = [
    'dossier' => ['Dossier'],
    'trashedDossier' => ['Dossier'],
    'documentRequestWithDossier' => ['Dossier', 'DocumentRequest'],
    'documentRequest' => ['DocumentRequest'],
    'documentRequestsFor' => ['DocumentRequest'],
];

/**
 * Every application PHP file, as paths relative to the project root.
 *
 * @return list<string>
 */
function applicationPhpFiles(): array
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
 * The models a file locks, in the order the locks are acquired.
 *
 * Recognises both idioms: the raw one DossierLocks uses internally
 * (`$fooQuery = Model::query(); $fooQuery->getQuery()->lockForUpdate();`) and a
 * `$this->dossierLocks->method()` call, which locks on the caller's behalf.
 *
 * @return list<string>
 */
function lockedModelsIn(string $relativePath): array
{
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$relativePath);

    $pattern = '/(?<assign>\$(?<var>\w+)\s*=\s*'.MODEL_REFERENCE.'::(?:query|withTrashed)\()'
        .'|(?<lock>\$(?<lockVar>\w+)->getQuery\(\)->lockForUpdate\(\))'
        .'|(?<helper>->(?<method>'.implode('|', array_keys(DOSSIER_LOCKS_METHODS)).')\()/';

    preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);

    /** @var array<string, string> $modelByVar */
    $modelByVar = [];
    /** @var list<string> $locked */
    $locked = [];

    foreach ($matches as $match) {
        if (($match['assign'] ?? '') !== '') {
            $modelByVar[$match['var']] = $match['model'];

            continue;
        }

        if (($match['lock'] ?? '') !== '') {
            $model = $modelByVar[$match['lockVar']] ?? null;

            if ($model !== null && array_key_exists($model, CANONICAL_LOCK_RANK)) {
                $locked[] = $model;
            }

            continue;
        }

        if (($match['helper'] ?? '') !== '') {
            foreach (DOSSIER_LOCKS_METHODS[$match['method']] as $model) {
                $locked[] = $model;
            }
        }
    }

    return $locked;
}

test('the lock scanner finds the locks it is meant to police', function (): void {
    // A source scanner that quietly matches nothing would pass every assertion
    // below, so prove it sees both idioms before trusting it.
    expect(lockedModelsIn(LOCK_OWNER))
        ->toContain('Dossier', 'DocumentRequest')
        ->and(lockedModelsIn('app/ClientPortal/Actions/Submissions/SubmitPortalAnswerAction.php'))
        ->toBe(['Dossier', 'DocumentRequest', 'ClientAccessGrant']);
});

test('ranked locks are only ever taken in canonical order', function (): void {
    /** @var array<string, list<string>> $violations */
    $violations = [];

    foreach (applicationPhpFiles() as $path) {
        // DossierLocks defines the order rather than following it: its methods
        // are independent entry points, so their source positions carry no
        // ordering meaning. Its own locks are asserted by the scanner test and
        // by the runtime query-order test.
        if ($path === LOCK_OWNER) {
            continue;
        }

        $locked = lockedModelsIn($path);
        $ranks = array_map(fn (string $model): int => CANONICAL_LOCK_RANK[$model], $locked);
        $sorted = $ranks;
        sort($sorted);

        if ($ranks !== $sorted) {
            $violations[$path] = $locked;
        }
    }

    expect($violations)->toBe(
        [],
        'These files take dossier/document-request/grant locks out of canonical '
        .'order (dossiers -> document_requests -> client_access_grants), which '
        .'can deadlock against a transaction holding them the other way round.',
    );
});

test('only DossierLocks takes dossier and document-request locks', function (): void {
    /** @var array<string, list<string>> $violations */
    $violations = [];

    foreach (applicationPhpFiles() as $path) {
        if ($path === LOCK_OWNER) {
            continue;
        }

        $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);

        // Only raw lock calls count here; going through DossierLocks is the point.
        if (! str_contains($source, 'getQuery()->lockForUpdate()')) {
            continue;
        }

        $modelByVar = [];
        preg_match_all(
            '/\$(?<var>\w+)\s*=\s*'.MODEL_REFERENCE.'::(?:query|withTrashed)\(/',
            $source,
            $assignments,
            PREG_SET_ORDER,
        );

        foreach ($assignments as $assignment) {
            $modelByVar[$assignment['var']] = $assignment['model'];
        }

        preg_match_all('/\$(?<var>\w+)->getQuery\(\)->lockForUpdate\(\)/', $source, $locks, PREG_SET_ORDER);

        foreach ($locks as $lock) {
            $model = $modelByVar[$lock['var']] ?? null;

            if ($model === 'Dossier' || $model === 'DocumentRequest') {
                $violations[$path][] = $model;
            }
        }
    }

    expect($violations)->toBe(
        [],
        'Dossier and document-request row locks must go through '
        .'App\Dossiers\Support\DossierLocks. Locking either directly reopens the '
        .'lock-order deadlock, and a caller that locks one and lets another class '
        .'lock the other hides the real order from both files.',
    );
});

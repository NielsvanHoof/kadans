<?php

declare(strict_types=1);

use App\Audit\Models\Activity;

/*
|--------------------------------------------------------------------------
| Audit log tenant scoping
|--------------------------------------------------------------------------
|
| Activity extends Spatie's model rather than TenantOwnedModel, because the
| platform's own retention and data-subject paths have to read across firms.
| That means it carries a tenant_id column but no TenantScope: a bare
| Activity::query() silently reads every firm's trail, and the audit log is
| the widest-blast-radius table in the system.
|
| So the query entry points are named instead, the same way the rest of the
| codebase makes TenantOwnedModel::queryWithoutTenantScope() greppable:
|
|   Activity::queryForTenant($tenant)   — tenant-facing reads
|   Activity::queryAcrossTenants()      — platform paths, filtered by subject
|
| Both state their intent. A bare query() states nothing, so it fails here.
|
| Source scanning only. tests/Architecture has no TestCase bound in Pest.php,
| so nothing here may touch the container — no base_path(), no config(), no
| facades (see .ai/rules/tests.md).
|
*/

/**
 * Absolute paths of every PHP file under app/, minus the model that defines
 * the entry points. Derived from __DIR__ because the app is never booted here.
 *
 * @return list<string>
 */
function auditCallsiteFiles(): array
{
    $projectRoot = dirname(__DIR__, 2);
    $modelPath = new ReflectionClass(Activity::class)->getFileName();

    $files = [];

    $tree = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectRoot.'/app', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($tree as $file) {
        if (! $file instanceof SplFileInfo) {
            continue;
        }

        if ($file->getExtension() !== 'php') {
            continue;
        }

        $realPath = $file->getRealPath();

        if ($realPath === false || $realPath === $modelPath) {
            continue;
        }

        $files[] = $realPath;
    }

    sort($files);

    return $files;
}

test('no application code reads the audit log through a bare Activity::query()', function (): void {
    $projectRoot = dirname(__DIR__, 2);
    $offenders = [];

    foreach (auditCallsiteFiles() as $path) {
        $contents = file_get_contents($path);

        if ($contents === false) {
            continue;
        }

        // Only files that actually reference this model, so an unrelated
        // `SomethingElse::query()` is never mistaken for a callsite.
        if (! str_contains($contents, Activity::class)) {
            continue;
        }

        foreach (explode("\n", $contents) as $index => $line) {
            if (! str_contains($line, 'Activity::query()')) {
                continue;
            }

            $offenders[] = str_replace($projectRoot.'/', '', $path).':'.($index + 1);
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'Use Activity::queryForTenant($tenant) for tenant-facing reads, or',
        'Activity::queryAcrossTenants() when the query legitimately spans firms.',
        'Bare Activity::query() found at:',
        ...$offenders,
    ]));
});

test('the audit log exposes both named query entry points', function (): void {
    expect(method_exists(Activity::class, 'queryForTenant'))->toBeTrue()
        ->and(method_exists(Activity::class, 'queryAcrossTenants'))->toBeTrue();
});

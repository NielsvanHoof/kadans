<?php

declare(strict_types=1);

/**
 * Merge per-shard serialized coverage and enforce the PHP line floor.
 *
 * CI runs the suite once, split across time-balanced Pest shards, and each
 * shard writes `--coverage-php`. No single shard sees the whole app, so the
 * floor can only be judged after merging them.
 *
 * The metric matches Pest's own `--min`: Pest fails on
 * `$coverage < $min` (Pest\Plugins\Coverage) using
 * `$report->percentageOfExecutedLines()` (Pest\Support\Coverage), and both that
 * and `Summary::lineCoverageAsPercentage()` used here are executed/executable
 * lines over the `<source>` set in phpunit.xml. So the number printed here is
 * the number `--min=87` printed when one job ran the whole suite.
 *
 * A `.cov` file is not a CodeCoverage object — since php-code-coverage 14 it is
 * serialization format 3, a plain array. Merging therefore goes through the
 * library's own Merger, which also refuses to combine shards from different PHP
 * versions, coverage drivers or commits rather than reporting a bogus total.
 *
 * Usage:
 *   php scripts/coverage-gate.php --min=87 --expect=4 coverage-shards/*.cov
 *
 * @see .github/workflows/platform.yml
 */

use SebastianBergmann\CodeCoverage\Report\Facade;
use SebastianBergmann\CodeCoverage\Serialization\Merger;

/*
 * Merging four full-app coverage sets needs more than the CLI default; the gate
 * is the only step in its job, so it has the runner to itself.
 */
ini_set('memory_limit', '2G');

$autoload = __DIR__.'/../vendor/autoload.php';

if (! file_exists($autoload)) {
    fwrite(STDERR, "coverage-gate: vendor/autoload.php not found — run composer install first.\n");

    exit(1);
}

require $autoload;

$min = 0.0;
$expected = 0;
$paths = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--min=')) {
        $min = (float) mb_substr($argument, 6);

        continue;
    }

    if (str_starts_with($argument, '--expect=')) {
        $expected = (int) mb_substr($argument, 9);

        continue;
    }

    $paths[] = $argument;
}

/*
 * An empty list means the shard artifacts silently produced nothing — an
 * unmatched glob expands to itself, or the download step wrote no files.
 * Passing here would turn the gate into a no-op that reports success, which is
 * worse than a red build.
 */
if ($paths === []) {
    fwrite(STDERR, "coverage-gate: no coverage files given. Expected at least one --coverage-php artifact.\n");

    exit(1);
}

/*
 * A missing shard understates the total, so the floor stops meaning anything.
 * Fail on the count rather than on the resulting percentage, which would read
 * as a genuine coverage regression and send the next person hunting for one.
 */
if ($expected > 0 && count($paths) !== $expected) {
    fwrite(STDERR, sprintf(
        "coverage-gate: expected %d coverage files, received %d:\n  %s\n",
        $expected,
        count($paths),
        implode("\n  ", $paths),
    ));

    exit(1);
}

foreach ($paths as $path) {
    if (! is_file($path)) {
        fwrite(STDERR, sprintf("coverage-gate: coverage file [%s] does not exist.\n", $path));

        exit(1);
    }

    printf("Loading %s (%.1f MiB)\n", $path, ((int) filesize($path)) / 1048576);
}

try {
    $merged = new Merger()->merge($paths);
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf(
        "coverage-gate: could not merge the shard coverage files: %s\n",
        $exception->getMessage(),
    ));

    exit(1);
}

$summary = Facade::fromSerializedData($merged)->summary();
$percentage = $summary->lineCoverageAsPercentage();

printf(
    "\nMerged %d shards: %d of %d executable lines covered — %s%%\n",
    count($paths),
    $summary->numberOfExecutedLines(),
    $summary->numberOfExecutableLines(),
    number_format($percentage, 2),
);

if ($percentage < $min) {
    fwrite(STDERR, sprintf(
        "\ncoverage-gate: FAILED — %s%% is below the required minimum of %s%%.\n",
        number_format($percentage, 2),
        number_format($min, 1),
    ));

    exit(1);
}

printf("coverage-gate: PASSED — minimum required is %s%%.\n", number_format($min, 1));

exit(0);

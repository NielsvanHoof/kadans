<?php

declare(strict_types=1);

namespace App\Platform\Support\Localization;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function in_array;
use function is_string;

/**
 * Finds which source files reference which translation keys.
 *
 * Extracted from tests/Unit/LanguageCoverageTest.php so the coverage test and
 * `lang:organize` share one set of patterns. The regexes are unchanged: the
 * command decides where a key lives from the files that use it, so a scanner
 * that saw a different set of call sites than the coverage gate would place
 * keys the gate then reports as missing.
 *
 * Key => files is the primitive. The coverage test wants flat key lists and
 * gets them from flatten(); the command needs the paths.
 *
 * Deliberately dependency-free — no container, no config, no domain imports —
 * so it is layering-inert and usable from a pure-PHP Unit test with no booted
 * application.
 */
final readonly class TranslationCallSiteScanner
{
    /**
     * @param  array<string, list<string>>  $keysByFile
     * @return list<string>
     */
    public static function flatten(array $keysByFile): array
    {
        return array_values(array_unique(array_keys($keysByFile)));
    }

    /**
     * Keys passed as a literal first argument to __() in PHP sources.
     *
     * @return array<string, list<string>> key => absolute file paths
     */
    public function phpKeysByFile(string $directory): array
    {
        $keysByFile = [];

        foreach ($this->filesIn($directory, ['php']) as $file) {
            $path = $file->getPathname();
            $contents = (string) file_get_contents($path);

            if (preg_match_all("/__\\('((?:\\\\'|[^'])*)'/", $contents, $singleQuotedMatches) !== false) {
                foreach ($singleQuotedMatches[1] as $key) {
                    $keysByFile[str_replace("\\'", "'", $key)][] = $path;
                }
            }

            if (preg_match_all('/__\\("((?:\\\\"|[^"])*)"/', $contents, $doubleQuotedMatches) !== false) {
                foreach ($doubleQuotedMatches[1] as $key) {
                    $keysByFile[str_replace('\\"', '"', $key)][] = $path;
                }
            }
        }

        return $this->deduplicatePaths($keysByFile);
    }

    /**
     * Static t('…') / t("…") keys in frontend TypeScript sources.
     *
     * Skips dynamic calls like t(variable) or t(definition.label).
     *
     * @return array<string, list<string>> key => absolute file paths
     */
    public function frontendLiteralKeysByFile(string $directory): array
    {
        $keysByFile = [];

        foreach ($this->filesIn($directory, ['ts', 'tsx']) as $file) {
            $path = $file->getPathname();
            $contents = (string) file_get_contents($path);

            // Match t('key') and t("key"), including keys that span multiple lines.
            if (preg_match_all("/\\bt\\(\\s*(['\"])(.*?)\\1/s", $contents, $matches) === false) {
                continue;
            }

            foreach ($matches[2] as $key) {
                $normalized = $this->normalize($key);

                if ($normalized !== null) {
                    $keysByFile[$normalized][] = $path;
                }
            }
        }

        return $this->deduplicatePaths($keysByFile);
    }

    /**
     * English strings in frontend label maps that are later passed to t().
     *
     * Catches `_LABELS` / `labels` object literals (e.g. pipeline status
     * modules) that never appear as literal t('…') calls and would otherwise
     * escape coverage.
     *
     * @return array<string, list<string>> key => absolute file paths
     */
    public function frontendLabelMapKeysByFile(string $directory): array
    {
        $keysByFile = [];

        foreach ($this->filesIn($directory, ['ts', 'tsx']) as $file) {
            $path = $file->getPathname();
            $contents = (string) file_get_contents($path);

            // const FOO_LABELS = { … } / export const FOO_LABELS: Record<…> = { … }
            // and inline `const labels: Record<string, string> = { … }`.
            if (preg_match_all(
                '/(?:export\s+)?const\s+(?:\w+_LABELS|labels)\b[^=]*=\s*\{(.*?)\};/s',
                $contents,
                $mapMatches,
            ) === false) {
                continue;
            }

            foreach ($mapMatches[1] as $mapBody) {
                if (preg_match_all("/:\\s*(['\"])(.*?)\\1/s", $mapBody, $valueMatches) === false) {
                    continue;
                }

                foreach ($valueMatches[2] as $value) {
                    $normalized = $this->normalize($value);

                    if ($normalized !== null) {
                        $keysByFile[$normalized][] = $path;
                    }
                }
            }
        }

        return $this->deduplicatePaths($keysByFile);
    }

    /**
     * Every key referenced anywhere, with the files that reference it.
     *
     * @return array<string, list<string>> key => absolute file paths
     */
    public function keysByFile(string $phpDirectory, string $frontendDirectory): array
    {
        $merged = $this->phpKeysByFile($phpDirectory);

        foreach ([
            $this->frontendLiteralKeysByFile($frontendDirectory),
            $this->frontendLabelMapKeysByFile($frontendDirectory),
        ] as $keysByFile) {
            foreach ($keysByFile as $key => $paths) {
                $merged[$key] = [...($merged[$key] ?? []), ...$paths];
            }
        }

        return $this->deduplicatePaths($merged);
    }

    /**
     * Collapse whitespace from multiline string literals; null when nothing is left.
     */
    private function normalize(string $key): ?string
    {
        $normalized = preg_replace('/\s+/', ' ', $key);

        if (! is_string($normalized) || $normalized === '') {
            return null;
        }

        return mb_trim($normalized);
    }

    /**
     * @param  array<string, list<string>>  $keysByFile
     * @return array<string, list<string>>
     */
    private function deduplicatePaths(array $keysByFile): array
    {
        return array_map(
            static fn (array $paths): array => array_values(array_unique($paths)),
            $keysByFile,
        );
    }

    /**
     * @param  list<string>  $extensions
     * @return list<SplFileInfo>
     */
    private function filesIn(string $directory, array $extensions): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (! in_array($file->getExtension(), $extensions, true)) {
                continue;
            }

            $files[] = $file;
        }

        return $files;
    }
}

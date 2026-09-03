<?php

declare(strict_types=1);

namespace App\Platform\Console\Commands;

use App\Accounts\Enums\Locale;
use App\Platform\Support\Localization\TranslationCallSiteScanner;
use App\Shared\Support\Localization\TranslationDomains;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use RuntimeException;

use function array_key_exists;
use function count;
use function dirname;
use function in_array;
use function is_array;
use function is_string;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Distributes translation keys into the domain that uses them.
 *
 * Placement is derived, never hand-picked: a key goes to the single domain
 * referencing it, or to the fallback when several do or none does. That keeps
 * the answer stable under refactoring and independent of directory iteration
 * order, which is not the same on every filesystem.
 *
 * Mirrors questionnaire:generate-item-types and tenancy:generate-permissions.
 */
#[Signature('lang:organize {--check : Fail if the translation files are out of date}')]
#[Description('Distribute translation keys into per-domain files derived from their call sites')]
final class OrganizeTranslationsCommand extends Command
{
    private const int JsonFlags = JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR;

    public function __construct(private readonly TranslationCallSiteScanner $scanner)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $placement = $this->resolvePlacement();
        $dictionaries = [];

        foreach (Locale::values() as $locale) {
            $dictionaries[$locale] = $this->readCurrentDictionary($locale);
        }

        $this->guardLocaleParity($dictionaries);
        $this->guardPinnedKeysExist($dictionaries);

        $files = [];

        foreach ($dictionaries as $locale => $dictionary) {
            $files += $this->buildFiles($locale, $dictionary, $placement);
        }

        return $this->option('check')
            ? $this->reportStaleFiles($files)
            : $this->write($files, $dictionaries);
    }

    /**
     * Owning domain per key, from the files that reference it.
     *
     * @return array<string, string>
     */
    private function resolvePlacement(): array
    {
        /** @var array<string, string> $pathDomains */
        $pathDomains = Config::array('translations.domains');
        /** @var array<string, string> $pins */
        $pins = Config::array('translations.pins');

        // Longest prefix first, so pages/dossiers is never shadowed by a
        // shorter prefix that also matches.
        $prefixes = array_keys($pathDomains);
        usort($prefixes, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $keysByFile = $this->scanner->keysByFile(base_path('app'), base_path('resources/js'));
        $placement = [];

        foreach ($keysByFile as $key => $paths) {
            if (array_key_exists($key, $pins)) {
                $placement[$key] = $pins[$key];

                continue;
            }

            $domains = [];

            foreach ($paths as $path) {
                $relative = mb_ltrim(str_replace(base_path(), '', $path), '/');

                foreach ($prefixes as $prefix) {
                    if (str_starts_with($relative, $prefix)) {
                        $domains[$pathDomains[$prefix]] = true;

                        break;
                    }
                }
            }

            // One owner keeps it; several or none means it is not domain copy.
            $placement[$key] = count($domains) === 1
                ? array_key_first($domains)
                : TranslationDomains::FallbackDomain;
        }

        foreach ($pins as $key => $domain) {
            $placement[$key] = $domain;
        }

        return $placement;
    }

    /**
     * Every key currently in play for a locale.
     *
     * Reads the per-domain files first and the monolith second, mirroring
     * FileLoader::loadJsonPaths' base-path-last order, so the command sees the
     * same dictionary the framework does and is idempotent across the
     * migration boundary.
     *
     * @return array<string, string>
     */
    private function readCurrentDictionary(string $locale): array
    {
        $dictionary = [];

        foreach (TranslationDomains::NAMES as $domain) {
            $dictionary = [...$dictionary, ...$this->decode(
                base_path(TranslationDomains::relativePath($domain, $locale)),
            )];
        }

        return [...$dictionary, ...$this->decode(lang_path("{$locale}.json"))];
    }

    /**
     * @return array<string, string>
     */
    private function decode(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, array<string, string>>  $dictionaries
     */
    private function guardLocaleParity(array $dictionaries): void
    {
        $reference = Locale::English->value;
        $referenceKeys = array_keys($dictionaries[$reference] ?? []);

        foreach ($dictionaries as $locale => $dictionary) {
            $missing = array_diff($referenceKeys, array_keys($dictionary));
            $extra = array_diff(array_keys($dictionary), $referenceKeys);

            throw_unless(
                $missing === [] && $extra === [],
                RuntimeException::class,
                "Locale [{$locale}] does not match [{$reference}]. Missing: "
                    .json_encode(array_values($missing)).' Unexpected: '
                    .json_encode(array_values($extra)),
            );
        }
    }

    /**
     * A pin naming a key that is gone would otherwise become a silent orphan
     * allowlist entry — the gate would treat missing copy as intentional.
     *
     * @param  array<string, array<string, string>>  $dictionaries
     */
    private function guardPinnedKeysExist(array $dictionaries): void
    {
        /** @var array<string, string> $pins */
        $pins = Config::array('translations.pins');
        $reference = $dictionaries[Locale::English->value] ?? [];

        $missing = array_values(array_diff(array_keys($pins), array_keys($reference)));

        throw_unless(
            $missing === [],
            RuntimeException::class,
            'Pinned translation keys are missing from the dictionary: '
                .json_encode($missing),
        );
    }

    /**
     * Rendered contents keyed by absolute path.
     *
     * @param  array<string, string>  $dictionary
     * @param  array<string, string>  $placement
     * @return array<string, string>
     */
    private function buildFiles(string $locale, array $dictionary, array $placement): array
    {
        /** @var array<string, array<string, string>> $byDomain */
        $byDomain = array_fill_keys(TranslationDomains::NAMES, []);

        foreach ($dictionary as $key => $value) {
            $domain = $placement[$key] ?? TranslationDomains::FallbackDomain;

            // A pin or path map naming a domain outside NAMES would otherwise
            // write a file nothing registers, and the keys would vanish.
            throw_unless(
                array_key_exists($domain, $byDomain),
                RuntimeException::class,
                "Key [{$key}] is assigned to unknown domain [{$domain}].",
            );

            $byDomain[$domain][$key] = $value;
        }

        $files = [];

        foreach ($byDomain as $domain => $lines) {
            // SORT_STRING, never a Collator: ICU data differs between the CI
            // image and a developer machine, which would make --check flap
            // against a clean tree.
            ksort($lines, SORT_STRING);

            $files[base_path(TranslationDomains::relativePath($domain, $locale))]
                = json_encode($lines, self::JsonFlags)."\n";
        }

        return $files;
    }

    /**
     * @param  array<string, string>  $files
     */
    private function reportStaleFiles(array $files): int
    {
        $stale = [];

        foreach ($files as $path => $contents) {
            if (! File::exists($path) || File::get($path) !== $contents) {
                $stale[] = $this->relative($path);
            }
        }

        foreach (Locale::values() as $locale) {
            if (File::exists(lang_path("{$locale}.json"))) {
                $stale[] = "lang/{$locale}.json still exists and would override every domain";
            }
        }

        foreach ($this->unregisteredDomainDirectories() as $directory) {
            $stale[] = "{$directory} is not a registered translation domain";
        }

        if ($stale !== []) {
            warning('Translation files are out of date. Run: php artisan lang:organize');

            foreach ($stale as $entry) {
                warning("  {$entry}");
            }

            return self::FAILURE;
        }

        info('Translation files are up to date.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $files
     * @param  array<string, array<string, string>>  $dictionaries
     */
    private function write(array $files, array $dictionaries): int
    {
        $this->guardNothingLost($files, $dictionaries);

        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $contents);
        }

        info('Wrote '.count($files).' translation files across '
            .count(TranslationDomains::NAMES).' domains.');

        $unattributed = $this->unattributedKeys($dictionaries[Locale::English->value] ?? []);

        if ($unattributed !== []) {
            info(count($unattributed).' keys have no literal call site and were placed in '
                .TranslationDomains::FallbackDomain.'. Run with -v to list them.');

            if ($this->output->isVerbose()) {
                foreach ($unattributed as $key) {
                    $this->line("  {$key}");
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * Refuse to write unless every key and value survives the redistribution.
     *
     * Arithmetic rather than review: on 1,500 keys a dropped or duplicated key
     * is invisible in a diff, and the symptom months later is an English
     * sentence inside Dutch UI.
     *
     * @param  array<string, string>  $files
     * @param  array<string, array<string, string>>  $dictionaries
     */
    private function guardNothingLost(array $files, array $dictionaries): void
    {
        foreach ($dictionaries as $locale => $dictionary) {
            $merged = [];
            $lineCount = 0;

            foreach ($files as $path => $contents) {
                if (! str_ends_with($path, "/{$locale}.json")) {
                    continue;
                }

                /** @var array<string, string> $decoded */
                $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                $merged = [...$merged, ...$decoded];
                $lineCount += count($decoded);
            }

            throw_unless(
                $lineCount === count($merged),
                RuntimeException::class,
                "Locale [{$locale}] would write {$lineCount} keys across domains but only "
                    .count($merged).' survive the merge, so a key appears in two domains.',
            );

            ksort($merged, SORT_STRING);
            ksort($dictionary, SORT_STRING);

            throw_unless(
                $merged === $dictionary,
                RuntimeException::class,
                "Locale [{$locale}] would change the dictionary: "
                    .count($dictionary).' keys in, '.count($merged).' out.',
            );
        }
    }

    /**
     * @param  array<string, string>  $dictionary
     * @return list<string>
     */
    private function unattributedKeys(array $dictionary): array
    {
        $referenced = TranslationCallSiteScanner::flatten(
            $this->scanner->keysByFile(base_path('app'), base_path('resources/js')),
        );
        /** @var array<string, string> $pins */
        $pins = Config::array('translations.pins');

        $unattributed = array_values(array_diff(
            array_keys($dictionary),
            $referenced,
            array_keys($pins),
        ));

        sort($unattributed, SORT_STRING);

        return $unattributed;
    }

    /**
     * @return list<string>
     */
    private function unregisteredDomainDirectories(): array
    {
        $unregistered = [];

        foreach (File::directories(base_path('app')) as $domainPath) {
            $candidate = $domainPath.'/'.TranslationDomains::RelativeDirectory;

            if (! File::isDirectory($candidate)) {
                continue;
            }

            if (! in_array(basename($domainPath), TranslationDomains::NAMES, true)) {
                $unregistered[] = $this->relative($candidate);
            }
        }

        return $unregistered;
    }

    private function relative(string $path): string
    {
        $relative = str_replace(base_path(), '', $path);

        return is_string($relative) ? mb_ltrim($relative, '/') : $path;
    }
}

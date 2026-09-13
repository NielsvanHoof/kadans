<?php

declare(strict_types=1);

namespace App\Accounts\Actions\Localization;

use App\Accounts\Enums\Locale;
use App\Accounts\Exceptions\MissingTranslationsException;
use App\Shared\Support\Localization\TranslationDomains;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;

use function in_array;
use function is_array;

final class LoadFrontendTranslationsAction
{
    /**
     * Decoded dictionaries, keyed by locale.
     *
     * Bound as a singleton (PlatformServiceProvider), so under Octane this
     * survives the request: the domain files are read and decoded once per
     * worker instead of on every Inertia visit. The files only change on a
     * deploy, and a deploy restarts the workers.
     *
     * Bypassed when `app()->isLocal()` so a developer editing copy sees the
     * change on the next request rather than after up to 500 requests — the
     * stale memo otherwise looks like a missing domain registration and
     * pushes people to duplicate the key elsewhere.
     *
     * Deliberately a property rather than the once() helper, which
     * config/octane.php flushes on OperationTerminated — the same reasoning
     * PermissionRegistry records for its own cached array.
     *
     * @var array<string, array<string, string>>
     */
    private array $dictionaries = [];

    public function __construct(private readonly Filesystem $files) {}

    /**
     * @return array<string, string>
     */
    public function handle(string $locale): array
    {
        if (! in_array($locale, Locale::values(), true)) {
            $locale = Locale::English->value;
        }

        if (app()->isLocal()) {
            return $this->read($locale);
        }

        return $this->dictionaries[$locale] ??= $this->read($locale);
    }

    /**
     * Merge every domain file for a locale, last-wins within the domain list.
     *
     * Shared is first in NAMES so a specific domain beats the sink on collision
     * — the same order FileLoader sees after addJsonPath registration.
     *
     * @return array<string, string>
     */
    private function read(string $locale): array
    {
        $translations = [];

        foreach (TranslationDomains::NAMES as $domain) {
            $path = app_path(
                "{$domain}/".
                    TranslationDomains::RelativeDirectory.
                    "/{$locale}.json",
            );

            try {
                $contents = $this->files->get($path);
            } catch (FileNotFoundException) {
                throw MissingTranslationsException::forPath($path);
            }

            throw_if(
                mb_trim($contents) === '',
                MissingTranslationsException::forPath($path),
            );

            /** @var array<string, string> $chunk */
            $chunk = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            throw_unless(
                is_array($chunk),
                MissingTranslationsException::forPath($path),
            );

            $translations = [...$translations, ...$chunk];
        }

        return $translations;
    }
}

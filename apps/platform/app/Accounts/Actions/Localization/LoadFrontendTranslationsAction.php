<?php

declare(strict_types=1);

namespace App\Accounts\Actions\Localization;

use App\Accounts\Enums\Locale;
use Illuminate\Support\Facades\File;

use function in_array;
use function is_array;

final class LoadFrontendTranslationsAction
{
    /**
     * Decoded dictionaries, keyed by locale.
     *
     * Bound as a singleton (PlatformServiceProvider), so under Octane this
     * survives the request: the ~100 KB lang file is read and decoded once per
     * worker instead of on every Inertia visit. The files only change on a
     * deploy, and a deploy restarts the workers.
     *
     * Deliberately a property rather than the once() helper, which
     * config/octane.php flushes on OperationTerminated — the same reasoning
     * PermissionRegistry records for its own cached array.
     *
     * @var array<string, array<string, string>>
     */
    private array $dictionaries = [];

    /**
     * @return array<string, string>
     */
    public function handle(string $locale): array
    {
        if (! in_array($locale, Locale::values(), true)) {
            $locale = Locale::English->value;
        }

        return $this->dictionaries[$locale] ??= $this->read($locale);
    }

    /**
     * @return array<string, string>
     */
    private function read(string $locale): array
    {
        $path = lang_path("{$locale}.json");

        if (! File::exists($path)) {
            return [];
        }

        /** @var array<string, string>|null $translations */
        $translations = json_decode(File::get($path), true);

        return is_array($translations) ? $translations : [];
    }
}

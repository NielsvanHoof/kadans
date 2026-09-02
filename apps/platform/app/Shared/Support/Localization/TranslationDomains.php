<?php

declare(strict_types=1);

namespace App\Shared\Support\Localization;

/**
 * The domains that own user-facing copy, and where their files live.
 *
 * Copy sits beside the domain that owns it — `app/Dossiers/lang/{en,nl}.json` —
 * so deleting or moving a domain takes its copy with it.
 *
 * Lives in Shared (L0) rather than next to `lang:organize`, because
 * `LoadFrontendTranslationsAction` is in Accounts and `tests/Pest.php` forbids
 * `App\Accounts` from using `App\Platform`. It is a `const` rather than config
 * because `tests/Unit/LanguageCoverageTest.php` is pure PHP with no booted
 * application, so `Config` and `lang_path()` are both unavailable there.
 *
 * `Shared` is first deliberately. Laravel's FileLoader merges json paths with
 * `array_merge`, which is last-wins, so if a duplicate key ever escaped both
 * `lang:organize --check` and TranslationDomainsTest, the specific domain would
 * beat the catch-all rather than the other way round.
 */
final class TranslationDomains
{
    /** Directory inside each domain, lowercase because it holds no PHP namespace. */
    public const string RelativeDirectory = 'lang';

    /**
     * @var list<string>
     */
    public const array NAMES = [
        'Shared',
        'Accounts',
        'Audit',
        'ClientPortal',
        'Clients',
        'Dossiers',
        'Integrations',
        'Pipelines',
        'Platform',
        'Questionnaires',
        'Reporting',
        'Tenancy',
        'WorkspaceNotifications',
    ];

    /** Catch-all for copy used across domains, or by shared frontend chrome. */
    public const string FallbackDomain = 'Shared';

    /**
     * Path relative to the project root, for consumers with no booted app.
     */
    public static function relativePath(string $domain, string $locale): string
    {
        return "app/{$domain}/".self::RelativeDirectory."/{$locale}.json";
    }
}

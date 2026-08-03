<?php

declare(strict_types=1);

namespace App\Providers;

use App\Shared\TypeScript\DataAwareClassTransformer;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerApplicationServiceProvider as BaseTypeScriptTransformerServiceProvider;
use Spatie\TypeScriptTransformer\Formatters\PrettierFormatter;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptIdentifier;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;

/**
 * Generates TypeScript for every #[TypeScript] Data object and enum under app/.
 *
 * Replaces the hand-written mirrors that lived in
 * resources/js/features/<domain>/types.ts. Regenerate with
 * `php artisan typescript:transform`; wired into `npm run types:check` so a PHP
 * shape change cannot land without the TypeScript following it.
 */
final class TypeScriptTransformerServiceProvider extends BaseTypeScriptTransformerServiceProvider
{
    protected function configure(TypeScriptTransformerConfigFactory $config): void
    {
        $config
            ->transformer(DataAwareClassTransformer::class)
            ->transformer(EnumTransformer::class)
            ->transformDirectories(app_path())
            // Not in the scanned directories, so it would emit an unresolved
            // `undefined<...>`. Declared globally in resources/js/types/laravel.d.ts.
            ->replaceType(LengthAwarePaginator::class, new TypeScriptIdentifier('LaravelPaginated'))
            ->outputDirectory(resource_path('js/types/generated'))
            ->writer(new GlobalNamespaceWriter('app.d.ts'))
            ->formatter(PrettierFormatter::class)
            // The manifest is only a content hash for incremental runs; skipping it
            // keeps output deterministic for the CI --check guardrail and keeps a
            // churn-only file out of git.
            ->withoutManifest();
    }
}

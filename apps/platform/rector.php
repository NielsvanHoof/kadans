<?php

declare(strict_types=1);

use Pest\Rector\Rules\ToBeTrueNotFalseRector;
use Pest\Rector\Set\PestSetList;
use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetList;
use RectorLaravel\Set\LaravelSetProvider;

return RectorConfig::configure()
    // Application PHP sources only — skip generated/runtime directories.
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/bootstrap/providers.php',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withSkip([
        // Historical migrations should stay untouched once shipped.
        __DIR__.'/database/migrations',
        // Unsafe: turns not->toBeFalse() into toBeTrue() for string|int|false values.
        ToBeTrueNotFalseRector::class,
    ])
    ->withCache(
        cacheDirectory: __DIR__.'/storage/rector',
        cacheClass: FileCacheStorage::class,
    )
    ->withImportNames(
        removeUnusedImports: true,
    )
    ->withFluentCallNewLine()
    // Reads php version from composer.json (^8.5) and applies matching sets.
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
        carbon: true,
    )
    // Enable Laravel composer-based sets for the installed framework version.
    ->withSetProviders(LaravelSetProvider::class)
    ->withComposerBased(laravel: true)
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_IF_HELPERS,
        LaravelSetList::LARAVEL_TYPE_DECLARATIONS,
        LaravelSetList::LARAVEL_TESTING,
        LaravelSetList::LARAVEL_FACTORIES,
        PestSetList::CODING_STYLE,
    ]);

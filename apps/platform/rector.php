<?php

declare(strict_types=1);

use Pest\Rector\Rules\ToBeTrueNotFalseRector;
use Pest\Rector\Set\PestSetList;
use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;
use RectorLaravel\Rector\ArrayDimFetch\RequestVariablesToRequestFacadeRector;
use RectorLaravel\Rector\ArrayDimFetch\ServerVariableToRequestFacadeRector;
use RectorLaravel\Rector\Class_\AppendsPropertyToAppendsAttributeRector;
use RectorLaravel\Rector\Class_\CollectedByPropertyToCollectedByAttributeRector;
use RectorLaravel\Rector\Class_\ConnectionPropertyToConnectionAttributeRector;
use RectorLaravel\Rector\Class_\DateFormatPropertyToDateFormatAttributeRector;
use RectorLaravel\Rector\Class_\FillablePropertyToFillableAttributeRector;
use RectorLaravel\Rector\Class_\GuardedPropertyToGuardedAttributeRector;
use RectorLaravel\Rector\Class_\HiddenPropertyToHiddenAttributeRector;
use RectorLaravel\Rector\Class_\TablePropertyToTableAttributeRector;
use RectorLaravel\Rector\Class_\TouchesPropertyToTouchesAttributeRector;
use RectorLaravel\Rector\Class_\VisiblePropertyToVisibleAttributeRector;
use RectorLaravel\Rector\Class_\WithoutIncrementingPropertyToWithoutIncrementingAttributeRector;
use RectorLaravel\Rector\Class_\WithoutTimestampsPropertyToWithoutTimestampsAttributeRector;
use RectorLaravel\Rector\Empty_\EmptyToBlankAndFilledFuncRector;
use RectorLaravel\Rector\FuncCall\RemoveDumpDataDeadCodeRector;
use RectorLaravel\Rector\MethodCall\WhereToWhereLikeRector;
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
        // $_SERVER['argv'] must stay live for CLI detection; Request::server() is a snapshot.
        RequestVariablesToRequestFacadeRector::class,
        ServerVariableToRequestFacadeRector::class,
        // Prefer classic $fillable/$hidden (and related Eloquent properties) over #[Fillable]
        // attributes — readable next to ide-helper PHPDoc. Composer-based Laravel 13 sets
        // would otherwise convert these back on every run. Command #[Signature] stays enabled.
        AppendsPropertyToAppendsAttributeRector::class,
        CollectedByPropertyToCollectedByAttributeRector::class,
        ConnectionPropertyToConnectionAttributeRector::class,
        DateFormatPropertyToDateFormatAttributeRector::class,
        FillablePropertyToFillableAttributeRector::class,
        GuardedPropertyToGuardedAttributeRector::class,
        HiddenPropertyToHiddenAttributeRector::class,
        TablePropertyToTableAttributeRector::class,
        TouchesPropertyToTouchesAttributeRector::class,
        VisiblePropertyToVisibleAttributeRector::class,
        WithoutIncrementingPropertyToWithoutIncrementingAttributeRector::class,
        WithoutTimestampsPropertyToWithoutTimestampsAttributeRector::class,
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
        LaravelSetList::LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME,
        LaravelSetList::LARAVEL_ARRAYACCESS_TO_METHOD_CALL,
        PestSetList::CODING_STYLE,
    ])
    // Facade aliases → FQCN, but keep Eloquent: ide-helper writes @mixin \Eloquent for
    // Builder completion via _ide_helper.php. Renaming it to Model duplicates @mixin and
    // breaks that helper.
    ->withConfiguredRule(RenameClassRector::class, [
        'App' => 'Illuminate\Support\Facades\App',
        'Arr' => 'Illuminate\Support\Arr',
        'Artisan' => 'Illuminate\Support\Facades\Artisan',
        'Auth' => 'Illuminate\Support\Facades\Auth',
        'Blade' => 'Illuminate\Support\Facades\Blade',
        'Broadcast' => 'Illuminate\Support\Facades\Broadcast',
        'Bus' => 'Illuminate\Support\Facades\Bus',
        'Cache' => 'Illuminate\Support\Facades\Cache',
        'Config' => 'Illuminate\Support\Facades\Config',
        'Context' => 'Illuminate\Support\Facades\Context',
        'Cookie' => 'Illuminate\Support\Facades\Cookie',
        'Crypt' => 'Illuminate\Support\Facades\Crypt',
        'Date' => 'Illuminate\Support\Facades\Date',
        'DB' => 'Illuminate\Support\Facades\DB',
        'Event' => 'Illuminate\Support\Facades\Event',
        'File' => 'Illuminate\Support\Facades\File',
        'Gate' => 'Illuminate\Support\Facades\Gate',
        'Hash' => 'Illuminate\Support\Facades\Hash',
        'Http' => 'Illuminate\Support\Facades\Http',
        'Js' => 'Illuminate\Support\Js',
        'Lang' => 'Illuminate\Support\Facades\Lang',
        'Log' => 'Illuminate\Support\Facades\Log',
        'Mail' => 'Illuminate\Support\Facades\Mail',
        'Notification' => 'Illuminate\Support\Facades\Notification',
        'Number' => 'Illuminate\Support\Number',
        'Password' => 'Illuminate\Support\Facades\Password',
        'Process' => 'Illuminate\Support\Facades\Process',
        'Queue' => 'Illuminate\Support\Facades\Queue',
        'RateLimiter' => 'Illuminate\Support\Facades\RateLimiter',
        'Redirect' => 'Illuminate\Support\Facades\Redirect',
        'Request' => 'Illuminate\Support\Facades\Request',
        'Response' => 'Illuminate\Support\Facades\Response',
        'Route' => 'Illuminate\Support\Facades\Route',
        'Schema' => 'Illuminate\Support\Facades\Schema',
        'Session' => 'Illuminate\Support\Facades\Session',
        'Storage' => 'Illuminate\Support\Facades\Storage',
        'Str' => 'Illuminate\Support\Str',
        'URL' => 'Illuminate\Support\Facades\URL',
        'Validator' => 'Illuminate\Support\Facades\Validator',
        'View' => 'Illuminate\Support\Facades\View',
        'Vite' => 'Illuminate\Support\Facades\Vite',
    ])
    ->withRules([
        EmptyToBlankAndFilledFuncRector::class,
    ])
    ->withConfiguredRule(WhereToWhereLikeRector::class, [
        WhereToWhereLikeRector::USING_POSTGRES_DRIVER => true,
    ])
    ->withConfiguredRule(RemoveDumpDataDeadCodeRector::class, [
        'dd',
        'dump',
        'var_dump',
    ]);

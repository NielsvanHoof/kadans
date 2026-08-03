<?php

declare(strict_types=1);

namespace App\Shared\TypeScript;

use Override;
use Spatie\LaravelData\Support\DataConfig;
use Spatie\TypeScriptTransformer\ClassPropertyProcessors\FixArrayLikeStructuresClassPropertyProcessor;
use Spatie\TypeScriptTransformer\Transformers\AttributedClassTransformer;
use Spatie\TypeScriptTransformer\Transformers\ClassPropertyProcessors\ClassPropertyProcessor;

/**
 * AttributedClassTransformer plus laravel-data output-name mapping.
 *
 * @see MapsDataOutputNames for why the packaged transformer cannot be used.
 */
final class DataAwareClassTransformer extends AttributedClassTransformer
{
    /**
     * @return array<ClassPropertyProcessor>
     */
    #[Override]
    protected function classPropertyProcessors(): array
    {
        return [
            new FixArrayLikeStructuresClassPropertyProcessor(),
            new MapsDataOutputNames(resolve(DataConfig::class)),
        ];
    }
}

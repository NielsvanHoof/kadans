<?php

declare(strict_types=1);

namespace App\Shared\TypeScript;

use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Spatie\LaravelData\Contracts\BaseData;
use Spatie\LaravelData\Support\DataConfig;
use Spatie\TypeScriptTransformer\PhpNodes\PhpPropertyNode;
use Spatie\TypeScriptTransformer\Transformers\ClassPropertyProcessors\ClassPropertyProcessor;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptProperty;

/**
 * Rename generated properties to the name laravel-data actually serialises.
 *
 * Why this exists: our props are snake_case via the SnakeCaseMapper in
 * config/data.php, but typescript-transformer reads PHP property names, so it
 * would emit `dueDate` for a payload that really sends `due_date`.
 *
 * spatie/laravel-data ships a transformer that handles this
 * (DataTypeScriptTransformer), but it extends the v2 laravel-typescript-transformer
 * and fatals under v3 — and v2 caps at Laravel 12, so it cannot be used here.
 * This processor is the small adapter that closes that gap: it asks laravel-data
 * itself for each property's outputMappedName, so the two can never drift.
 */
final readonly class MapsDataOutputNames implements ClassPropertyProcessor
{
    public function __construct(private DataConfig $dataConfig) {}

    public function execute(
        PhpPropertyNode $phpPropertyNode,
        ?TypeNode $annotation,
        TypeScriptProperty $property,
    ): TypeScriptProperty {
        $class = $phpPropertyNode->getDeclaringClass()->getName();

        if (! is_subclass_of($class, BaseData::class)) {
            return $property;
        }

        $dataClass = $this->dataConfig->getDataClass($class);
        $name = $phpPropertyNode->getName();

        if (! isset($dataClass->properties[$name])) {
            return $property;
        }

        $mapped = $dataClass->properties[$name]->outputMappedName;

        if ($mapped === null || $mapped === $name) {
            return $property;
        }

        return new TypeScriptProperty(
            $mapped,
            $property->type,
            $property->isOptional,
            $property->isReadonly,
        );
    }
}

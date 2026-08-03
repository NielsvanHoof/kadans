<?php

declare(strict_types=1);

namespace App\Reporting\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Activity log index table row.
 */
#[TypeScript]
final class ActivityLogRowData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $event,
        public readonly string $label,
        public readonly string $description,
        public readonly ?string $causerName,
        public readonly ?ActivityLogSubjectData $subject,
        public readonly ?string $createdAt,
        public readonly ActivityLogPropertiesData $properties,
        public readonly ?ActivityLogAttributeChangesData $attributeChanges,
    ) {}
}

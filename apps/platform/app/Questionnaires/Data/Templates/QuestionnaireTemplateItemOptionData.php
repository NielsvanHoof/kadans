<?php

declare(strict_types=1);

namespace App\Questionnaires\Data\Templates;

use App\Questionnaires\Enums\QuestionnaireItemType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Lean template item for local apply in the dossier builder draft.
 */
#[TypeScript]
final class QuestionnaireTemplateItemOptionData extends Data
{
    /**
     * @param  array<string, mixed>|null  $configuration
     */
    public function __construct(
        public readonly QuestionnaireItemType $type,
        public readonly string $fieldKey,
        public readonly string $title,
        public readonly ?string $instructions,
        public readonly ?array $configuration,
        public readonly int $sortOrder,
    ) {}
}

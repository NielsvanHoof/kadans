<?php

declare(strict_types=1);

namespace App\Questionnaires\Data\Templates;

use App\Questionnaires\Enums\QuestionnaireTemplateCategory;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Questionnaire template index / apply-template option row.
 */
#[TypeScript]
final class QuestionnaireTemplateRowData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $description,
        public readonly QuestionnaireTemplateCategory $category,
        public readonly string $categoryLabel,
        public readonly int $itemsCount,
        public readonly bool $isSystem,
    ) {}
}

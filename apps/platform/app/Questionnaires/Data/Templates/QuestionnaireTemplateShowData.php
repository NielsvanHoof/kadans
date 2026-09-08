<?php

declare(strict_types=1);

namespace App\Questionnaires\Data\Templates;

use App\Questionnaires\Enums\QuestionnaireTemplateCategory;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Full questionnaire template show payload.
 */
#[TypeScript]
final class QuestionnaireTemplateShowData extends Data
{
    /**
     * @param  list<QuestionnaireTemplateItemData>  $items
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $description,
        public readonly QuestionnaireTemplateCategory $category,
        public readonly string $categoryLabel,
        public readonly bool $isSystem,
        public readonly array $items,
    ) {}
}

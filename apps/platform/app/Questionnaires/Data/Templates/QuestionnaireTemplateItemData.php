<?php

declare(strict_types=1);

namespace App\Questionnaires\Data;

use App\Questionnaires\Enums\QuestionnaireItemType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Template item on the questionnaire template show page.
 */
#[TypeScript]
final class QuestionnaireTemplateItemData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly QuestionnaireItemType $type,
        public readonly string $title,
        public readonly ?string $instructions,
        public readonly int $sortOrder,
    ) {}
}

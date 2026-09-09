<?php

declare(strict_types=1);

namespace App\Questionnaires\Data\Templates;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Apply-template option on the dossier builder (includes items for local draft apply).
 */
#[TypeScript]
final class QuestionnaireTemplateOptionData extends Data
{
    /**
     * @param  list<QuestionnaireTemplateItemOptionData>  $items
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $categoryLabel,
        public readonly int $itemsCount,
        public readonly bool $isSystem,
        public readonly array $items = [],
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Questionnaires\Data;

use App\Questionnaires\Enums\QuestionnaireItemType;

/**
 * A single AI-suggested checklist item — not persisted, only proposed for
 * the user to review before adding it through the existing item form.
 */
final readonly class DraftedChecklistItemData
{
    public function __construct(
        public string $title,
        public string $instructions,
        public QuestionnaireItemType $type,
    ) {}

    /** @return array{title: string, instructions: string, type: string} */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'instructions' => $this->instructions,
            'type' => $this->type->value,
        ];
    }
}

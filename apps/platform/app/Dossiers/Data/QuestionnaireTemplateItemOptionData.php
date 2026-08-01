<?php

declare(strict_types=1);

namespace App\Dossiers\Data;

/**
 * Lean template item for local apply in the dossier builder draft.
 */
final readonly class QuestionnaireTemplateItemOptionData
{
    /**
     * @param  array<string, mixed>|null  $configuration
     */
    public function __construct(
        public string $type,
        public string $fieldKey,
        public string $title,
        public ?string $instructions,
        public ?array $configuration,
        public int $sortOrder,
    ) {}

    /**
     * @return array{
     *     type: string,
     *     field_key: string,
     *     title: string,
     *     instructions: string|null,
     *     configuration: array<string, mixed>|null,
     *     sort_order: int
     * }
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'field_key' => $this->fieldKey,
            'title' => $this->title,
            'instructions' => $this->instructions,
            'configuration' => $this->configuration,
            'sort_order' => $this->sortOrder,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Dossiers\Data;

/**
 * Apply-template option on the dossier builder (includes items for local draft apply).
 */
final readonly class QuestionnaireTemplateOptionData
{
    /**
     * @param  list<QuestionnaireTemplateItemOptionData>  $items
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $categoryLabel,
        public int $itemsCount,
        public bool $isSystem,
        public array $items = [],
    ) {}

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     category_label: string,
     *     items_count: int,
     *     is_system: bool,
     *     items: list<array{
     *         type: string,
     *         field_key: string,
     *         title: string,
     *         instructions: string|null,
     *         configuration: array<string, mixed>|null,
     *         sort_order: int
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category_label' => $this->categoryLabel,
            'items_count' => $this->itemsCount,
            'is_system' => $this->isSystem,
            'items' => array_map(
                static fn (QuestionnaireTemplateItemOptionData $item): array => $item->toArray(),
                $this->items,
            ),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Data;

final readonly class PipelineSubjectData
{
    public function __construct(
        public string $type,
        public int $id,
        public ?string $label,
        public ?string $context,
        public ?string $href,
    ) {}

    /**
     * @return array{type: string, id: int, label: string|null, context: string|null, href: string|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'label' => $this->label,
            'context' => $this->context,
            'href' => $this->href,
        ];
    }
}

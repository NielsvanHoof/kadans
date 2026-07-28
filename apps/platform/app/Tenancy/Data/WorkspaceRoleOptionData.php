<?php

declare(strict_types=1);

namespace App\Tenancy\Data;

/**
 * Role select option shown when inviting or updating a member.
 */
final readonly class WorkspaceRoleOptionData
{
    public function __construct(
        public string $value,
        public string $label,
    ) {}

    /**
     * @return array{value: string, label: string}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label,
        ];
    }
}

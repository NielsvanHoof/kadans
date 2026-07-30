<?php

declare(strict_types=1);

namespace App\Tenancy\Data;

/**
 * One role column on the roles Inertia page.
 */
final readonly class WorkspaceRoleData
{
    public function __construct(
        public string $value,
        public string $label,
        public bool $isSystem,
        public int $memberCount,
    ) {}

    /**
     * @return array{
     *     value: string,
     *     label: string,
     *     is_system: bool,
     *     member_count: int
     * }
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label,
            'is_system' => $this->isSystem,
            'member_count' => $this->memberCount,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Tenancy\Data;

/**
 * One permission row on the roles Inertia page.
 *
 * `grantedTo` holds role names rather than a role-keyed map so the payload
 * stays flat: the matrix is rendered by testing membership of this list.
 */
final readonly class WorkspacePermissionData
{
    /**
     * @param  list<string>  $grantedTo
     */
    public function __construct(
        public string $value,
        public string $label,
        public string $description,
        public array $grantedTo,
    ) {}

    /**
     * @return array{
     *     value: string,
     *     label: string,
     *     description: string,
     *     granted_to: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label,
            'description' => $this->description,
            'granted_to' => $this->grantedTo,
        ];
    }
}

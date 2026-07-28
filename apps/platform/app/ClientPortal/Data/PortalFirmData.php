<?php

declare(strict_types=1);

namespace App\ClientPortal\Data;

/**
 * Firm branding snippet on the client portal.
 */
final readonly class PortalFirmData
{
    public function __construct(public string $name) {}

    /**
     * @return array{name: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name];
    }
}

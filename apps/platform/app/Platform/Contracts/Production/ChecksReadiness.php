<?php

declare(strict_types=1);

namespace App\Platform\Contracts\Production;

interface ChecksReadiness
{
    public function check(): void;
}

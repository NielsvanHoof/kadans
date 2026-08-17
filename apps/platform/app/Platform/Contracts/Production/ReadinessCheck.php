<?php

declare(strict_types=1);

namespace App\Platform\Contracts\Production;

interface ReadinessCheck
{
    public function check(): void;
}

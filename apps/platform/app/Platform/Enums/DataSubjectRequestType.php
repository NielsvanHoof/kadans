<?php

declare(strict_types=1);

namespace App\Platform\Enums;

/**
 * The two rights a client can exercise over the data a firm holds about them.
 */
enum DataSubjectRequestType: string
{
    /** GDPR Art. 15/20 — a copy of everything held, in a portable form. */
    case Export = 'export';

    /** GDPR Art. 17 — erasure, subject to the firm's own retention duty. */
    case Erasure = 'erasure';

    public function label(): string
    {
        return match ($this) {
            self::Export => __('Data export'),
            self::Erasure => __('Data erasure'),
        };
    }
}

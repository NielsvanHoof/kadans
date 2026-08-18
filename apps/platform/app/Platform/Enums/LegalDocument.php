<?php

declare(strict_types=1);

namespace App\Platform\Enums;

/**
 * Public legal documents served at /legal/{document}.
 */
enum LegalDocument: string
{
    case Privacy = 'privacy';
    case Cookies = 'cookies';
    case Terms = 'terms';

    public function title(): string
    {
        return match ($this) {
            self::Privacy => __('Privacy policy'),
            self::Cookies => __('Cookie statement'),
            self::Terms => __('Terms of service'),
        };
    }

    /**
     * Markdown filename under resources/legal/{locale}/.
     */
    public function markdownFile(): string
    {
        return $this->value.'.md';
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Inertia\Inertia;

/**
 * Flash a toast onto the next Inertia response.
 *
 * The payload shape is a contract with the FlashToast type in
 * resources/js/types/ui.ts and the useFlashToast hook — keep them in step.
 */
final readonly class Toast
{
    public static function success(string $message): void
    {
        self::flash('success', $message);
    }

    public static function info(string $message): void
    {
        self::flash('info', $message);
    }

    public static function warning(string $message): void
    {
        self::flash('warning', $message);
    }

    public static function error(string $message): void
    {
        self::flash('error', $message);
    }

    /**
     * Flash a toast and return the payload.
     *
     * Public only for JSON endpoints that must echo the same flash inside their
     * response body — see DocumentRequestController::sync. Prefer the four named
     * helpers above everywhere else.
     *
     * @return array{type: string, message: string}
     */
    public static function flash(string $type, string $message): array
    {
        $payload = [
            'type' => $type,
            'message' => $message,
        ];

        Inertia::flash('toast', $payload);

        return $payload;
    }
}

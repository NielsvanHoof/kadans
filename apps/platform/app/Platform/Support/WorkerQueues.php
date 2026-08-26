<?php

declare(strict_types=1);

namespace App\Platform\Support;

/**
 * Queues Horizon (and local Sail via `php artisan horizon`) must drain.
 *
 * Order matters: default first so interactive jobs are not starved behind OCR.
 * `dossier-export` carries every long-running archive build — dossier exports
 * and data-subject exports alike — for the same reason: they must not sit in
 * front of a broadcast or a notification.
 *
 * `config/horizon.php` supervisors use {@see NAMES}. Laravel Cloud runs a
 * single custom background process `php artisan horizon` (not queue:work);
 * the queue CSV field on managed queue:work workers is obsolete.
 */
final class WorkerQueues
{
    /** @var list<string> */
    public const array NAMES = [
        'default',
        'ocr',
        'transcription',
        'dossier-export',
    ];

    public static function csv(): string
    {
        return implode(',', self::NAMES);
    }

    /**
     * Dedicated queues only — default is drained by the same Horizon supervisor.
     *
     * @return list<string>
     */
    public static function dedicatedNames(): array
    {
        return array_values(array_filter(
            self::NAMES,
            fn (string $queue): bool => $queue !== 'default',
        ));
    }
}

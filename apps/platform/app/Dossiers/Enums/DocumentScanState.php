<?php

declare(strict_types=1);

namespace App\Dossiers\Enums;

use Illuminate\Support\Facades\Config;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the interface should say about a file's security check.
 *
 * Deliberately a different, smaller vocabulary from MalwareScanStatus. That enum
 * holds GuardDuty's own values and answers "what did the scanner find"; this one
 * answers "what does the person looking at this page need to know", and the
 * answer is one of three things.
 *
 * The distinction is not decoration. THREATS_FOUND, UNSUPPORTED, ACCESS_DENIED
 * and FAILED are four materially different operational facts and one identical
 * user-facing fact: the file will not open. An operator gets the detail from the
 * audit log; a user gets Blocked.
 */
#[TypeScript]
enum DocumentScanState: string
{
    /** No verdict yet. The normal state for the first seconds after an upload. */
    case Scanning = 'scanning';
    case Ready = 'ready';
    /** Any verdict that is not clean, and any verdict this application cannot read. */
    case Blocked = 'blocked';

    /**
     * Derive the state a file should be shown in.
     *
     * The `enforced` short circuit is what keeps local and CI usable. With
     * scanning switched off nothing ever writes a verdict, so every file would
     * sit on Scanning forever and the interface would be permanently wrong about
     * documents that open perfectly well. Where the delivery gate is not
     * enforcing, there is nothing for this to report.
     */
    public static function fromScanStatus(?MalwareScanStatus $status): self
    {
        if (! Config::boolean('security.malware_scanning.enabled', false)) {
            return self::Ready;
        }

        if (! $status instanceof MalwareScanStatus) {
            return self::Scanning;
        }

        return $status->isClean() ? self::Ready : self::Blocked;
    }
}

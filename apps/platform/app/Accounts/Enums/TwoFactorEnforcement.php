<?php

declare(strict_types=1);

namespace App\Accounts\Enums;

use function is_string;

/**
 * How strictly staff must hold a second authentication factor.
 *
 * The cases are totally ordered by strictness — Off, then ByPermission, then
 * AllStaff — because each one's enforced set contains the previous one's. That
 * holds only while the gated permission (`security.two_factor.permission`) is a
 * single platform-level value. Make that key per-firm and the sets stop nesting,
 * `strictest()` starts returning wrong answers, and firms could weaken
 * enforcement by re-keying it. Do not make it per-firm.
 *
 * Keyed by a permission *string* rather than a permission enum on purpose:
 * Accounts sits below Dossiers in the domain layering, so it must not import
 * `UploadedDocumentPermission`. The indirection also lets an operator re-key
 * enforcement onto a different permission without a deploy.
 */
enum TwoFactorEnforcement: string
{
    /** No requirement. Staff may enrol, nothing makes them. */
    case Off = 'off';

    /** Every staff account must hold a confirmed second factor. */
    case AllStaff = 'all';

    /**
     * Only staff whose role in the current workspace carries the configured
     * permission — by default, the ability to download client documents.
     */
    case ByPermission = 'by_permission';

    /**
     * Parse a configured value, failing closed.
     *
     * A typo in the deploy environment must not silently disable enforcement,
     * so an unrecognised value lands on the strictest level rather than the
     * loosest. Lives on the enum because both the Accounts middleware and the
     * Tenancy settings bound need the same answer, and Accounts may not import
     * Tenancy.
     */
    public static function fromConfigValue(mixed $value): self
    {
        return (is_string($value) ? self::tryFrom($value) : null) ?? self::AllStaff;
    }

    /**
     * The strictest of the given levels.
     *
     * Callers apply this themselves rather than trusting a collaborator to
     * return an already-clamped value: the result is then guaranteed to be at
     * least as strict as the platform floor by construction, so no override —
     * mistaken, hand-edited in the database, or faked in a test — can weaken
     * enforcement below what ProductionConfigurationValidator accepted at boot.
     */
    public static function strictest(self $first, self ...$rest): self
    {
        return array_reduce(
            $rest,
            static fn (self $carry, self $level): self => $level->severity() > $carry->severity()
                ? $level
                : $carry,
            $first,
        );
    }

    /**
     * Higher is stricter. Used to clamp a firm override so it can only ever
     * tighten what the platform config asks for.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Off => 0,
            self::ByPermission => 1,
            self::AllStaff => 2,
        };
    }

    public function isAtLeastAsStrictAs(self $other): bool
    {
        return $this->severity() >= $other->severity();
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Observability;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

use function preg_match;

/**
 * The fields every log record, queued job, and error report is stamped with.
 *
 * Values live in Laravel's Context rather than a static property, so they are
 * appended to log entries automatically, travel across the queue boundary with
 * the job payload, and stay request-scoped under Octane instead of leaking into
 * the next request on a reused worker.
 *
 * Only non-sensitive scalars belong here: Context is written into log output
 * *and* serialized into the queue payload.
 */
final class ObservabilityContext
{
    public const string RequestId = 'request_id';

    public const string TenantId = 'tenant_id';

    public const string UserId = 'user_id';

    public const string Route = 'route';

    public const string Command = 'command';

    /** Correlation id accepted from, and echoed back to, the load balancer. */
    public const string RequestIdHeader = 'X-Request-Id';

    /**
     * An inbound id is attacker-controlled, and whatever it holds is written
     * verbatim into every log line and queue payload for the rest of the
     * request. Accept only short opaque tokens; generate one otherwise.
     */
    private const string RequestIdPattern = '/^[A-Za-z0-9._:-]{1,64}$/';

    /**
     * Adopt the proxy's correlation id when it is well-formed, otherwise mint
     * one. ULIDs sort by creation time, which keeps log searches readable.
     */
    public static function rememberRequestId(?string $inbound): string
    {
        $requestId = $inbound !== null && preg_match(self::RequestIdPattern, $inbound) === 1
            ? $inbound
            : Str::ulid()->toString();

        Context::add(self::RequestId, $requestId);

        return $requestId;
    }

    public static function rememberRoute(?string $route): void
    {
        self::remember(self::Route, $route);
    }

    public static function rememberTenant(int|string|null $tenantId): void
    {
        self::remember(self::TenantId, $tenantId);
    }

    public static function forgetTenant(): void
    {
        Context::forget(self::TenantId);
    }

    public static function rememberUser(int|string|null $userId): void
    {
        self::remember(self::UserId, $userId);
    }

    public static function rememberCommand(string $command): void
    {
        self::remember(self::Command, $command);
    }

    private static function remember(string $key, int|string|null $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        Context::add($key, $value);
    }
}

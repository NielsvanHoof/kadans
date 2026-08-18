<?php

declare(strict_types=1);

namespace App\Platform\Support\Sentry;

use App\Shared\Observability\ObservabilityContext;
use Illuminate\Support\Facades\Context;
use Sentry\Event;
use Sentry\Integration\IntegrationInterface;
use Sentry\State\Scope;
use Sentry\UserDataBag;

use function is_int;
use function is_string;

/**
 * Attribute every Sentry event to a request, tenant, and user.
 *
 * Without this an error says what broke but not for whom — unusable when the
 * question is "is one firm affected or all of them".
 *
 * Registered as an SDK integration (a class name in config/sentry.php) rather
 * than a `before_send` closure, because config must stay `config:cache`
 * serializable. A global event processor also reads the context at capture
 * time, which is what makes tenant and user correct: both are stamped by
 * listeners partway through the request, not when the SDK boots.
 */
final class StampsEventsWithObservabilityContext implements IntegrationInterface
{
    public static function stamp(Event $event): Event
    {
        foreach ([
            ObservabilityContext::RequestId,
            ObservabilityContext::TenantId,
            ObservabilityContext::Route,
            ObservabilityContext::Command,
        ] as $key) {
            $value = Context::get($key);

            if (is_string($value) || is_int($value)) {
                $event->setTag($key, (string) $value);
            }
        }

        self::stampUser($event);

        return $event;
    }

    public function setupOnce(): void
    {
        Scope::addGlobalEventProcessor(self::stamp(...));
    }

    /**
     * The id only — never name, email, or IP. `send_default_pii` is off, and a
     * pseudonymous key is enough to tell one affected user from many.
     */
    private static function stampUser(Event $event): void
    {
        $userId = Context::get(ObservabilityContext::UserId);

        if (! is_string($userId) && ! is_int($userId)) {
            return;
        }

        $user = $event->getUser();

        $event->setUser(
            $user instanceof UserDataBag
                ? $user->setId($userId)
                : UserDataBag::createFromUserIdentifier($userId),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Platform\Support\Sentry;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\Integration\IntegrationInterface;
use Sentry\State\Scope;

use function is_string;
use function preg_replace;

/**
 * Keep client portal magic-link tokens out of error reports.
 *
 * `send_default_pii` being off does not help here: Sentry treats the request
 * URL as non-personal and records it either way. The portal exchange route
 * carries the grant token in the path (`/portal/access/{token}`), so any
 * exception on that route would ship a live credential to a third party —
 * one that stays valid until the grant expires, up to 90 days later.
 *
 * The same value also reaches `transaction` and breadcrumb URLs, so all three
 * are rewritten.
 */
final class RedactsPortalTokensFromEvents implements IntegrationInterface
{
    private const string TokenPathPattern = '#(/portal/access/)[A-Za-z0-9]+#';

    private const string Replacement = '$1[redacted]';

    public static function redact(Event $event): Event
    {
        $request = $event->getRequest();

        if (isset($request['url']) && is_string($request['url'])) {
            $request['url'] = self::scrub($request['url']);
            $event->setRequest($request);
        }

        $transaction = $event->getTransaction();

        if ($transaction !== null) {
            $event->setTransaction(self::scrub($transaction));
        }

        // Breadcrumb::withMetadata() returns a clone, so the rewritten ones have
        // to be put back on the event rather than mutated in place.
        $event->setBreadcrumb(array_map(
            static function (Breadcrumb $breadcrumb): Breadcrumb {
                $url = $breadcrumb->getMetadata()['url'] ?? null;

                return is_string($url)
                    ? $breadcrumb->withMetadata('url', self::scrub($url))
                    : $breadcrumb;
            },
            $event->getBreadcrumbs(),
        ));

        return $event;
    }

    public function setupOnce(): void
    {
        Scope::addGlobalEventProcessor(self::redact(...));
    }

    private static function scrub(string $value): string
    {
        return (string) preg_replace(self::TokenPathPattern, self::Replacement, $value);
    }
}

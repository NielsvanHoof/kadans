<?php

declare(strict_types=1);

use App\Platform\Support\Sentry\RedactsPortalTokensFromEvents;
use App\Platform\Support\Sentry\StampsEventsWithObservabilityContext;
use App\Shared\Observability\ObservabilityContext;
use Illuminate\Support\Facades\Context;
use Sentry\Breadcrumb;
use Sentry\Event;

beforeEach(function (): void {
    Context::flush();
});

test('both application integrations are registered as cacheable class names', function (): void {
    expect(config('sentry.integrations'))->toBe([
        RedactsPortalTokensFromEvents::class,
        StampsEventsWithObservabilityContext::class,
    ])
        ->and(config('sentry.integrations'))->each->toBeString();
});

test('an event is attributed to its request, tenant, route, and user', function (): void {
    ObservabilityContext::rememberRequestId('01JBX7Q9Z8TESTREQUESTID');
    ObservabilityContext::rememberTenant(42);
    ObservabilityContext::rememberRoute('workspaces.dossiers.show');
    ObservabilityContext::rememberUser(7);

    $event = StampsEventsWithObservabilityContext::stamp(Event::createEvent());

    expect($event->getTags())->toBe([
        ObservabilityContext::RequestId => '01JBX7Q9Z8TESTREQUESTID',
        ObservabilityContext::TenantId => '42',
        ObservabilityContext::Route => 'workspaces.dossiers.show',
    ])->and($event->getUser()?->getId())->toBe(7);
});

test('an event carries no user data beyond the id', function (): void {
    ObservabilityContext::rememberUser(7);

    $user = StampsEventsWithObservabilityContext::stamp(Event::createEvent())->getUser();

    expect($user?->getId())->toBe(7)
        ->and($user?->getEmail())->toBeNull()
        ->and($user?->getUsername())->toBeNull()
        ->and($user?->getIpAddress())->toBeNull();
});

test('an event with no context is left untagged rather than tagged with nulls', function (): void {
    $event = StampsEventsWithObservabilityContext::stamp(Event::createEvent());

    expect($event->getTags())
        ->toBeEmpty()
        ->and($event->getUser())->toBeNull();
});

/**
 * A grant token stays valid until the grant expires — up to 90 days — so
 * shipping one to a third-party error tracker is a live credential leak.
 * `send_default_pii` does not cover this: Sentry records the request URL
 * regardless, because it does not consider a URL personal data.
 */
test('a portal magic-link token never reaches Sentry', function (): void {
    $token = str_repeat('a1B2c3D4', 8);

    $event = Event::createEvent();
    $event->setRequest(['url' => "https://app.test/portal/access/{$token}"]);
    $event->setTransaction("GET /portal/access/{$token}");
    $event->setBreadcrumb([
        new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_NAVIGATION,
            'navigation',
            null,
            ['url' => "https://app.test/portal/access/{$token}"],
        ),
    ]);

    $redacted = RedactsPortalTokensFromEvents::redact($event);

    expect($redacted->getRequest()['url'])->toBe('https://app.test/portal/access/[redacted]')
        ->and($redacted->getTransaction())->toBe('GET /portal/access/[redacted]')
        ->and($redacted->getBreadcrumbs()[0]->getMetadata()['url'])
        ->toBe('https://app.test/portal/access/[redacted]')
        ->and(json_encode($redacted->getRequest()).$redacted->getTransaction())->not->toContain($token);
});

test('redaction leaves ordinary workspace urls untouched', function (): void {
    $event = Event::createEvent();
    $event->setRequest(['url' => 'https://acme.qompose.test/dossiers/12']);

    expect(RedactsPortalTokensFromEvents::redact($event)->getRequest()['url'])
        ->toBe('https://acme.qompose.test/dossiers/12');
});

test('redaction tolerates an event with no request, transaction, or breadcrumbs', function (): void {
    $event = RedactsPortalTokensFromEvents::redact(Event::createEvent());

    expect($event->getRequest())
        ->toBeEmpty()
        ->and($event->getTransaction())->toBeNull()
        ->and($event->getBreadcrumbs())
        ->toBeEmpty();
});

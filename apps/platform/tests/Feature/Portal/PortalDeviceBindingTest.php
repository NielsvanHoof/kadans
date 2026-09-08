<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\ClientPortal\Actions\Access\CreateClientAccessGrantAction;
use App\ClientPortal\Actions\Session\RegisterClientPortalDeviceAction;
use App\ClientPortal\Enums\NewPortalDeviceAction;
use App\ClientPortal\Enums\PortalDeviceOutcome;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Notifications\Session\ClientPortalNewDeviceNotification;
use App\ClientPortal\Support\Session\PortalDeviceFingerprint;
use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Enums\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

covers(RegisterClientPortalDeviceAction::class, PortalDeviceFingerprint::class);

/**
 * A grant token stays re-exchangeable until it expires — deliberate, because
 * clients lose emails and open links on several devices. What was missing was
 * any way to notice a leaked link being used by someone else.
 */
const FIRST_DEVICE = [
    'REMOTE_ADDR' => '203.0.113.10',
    'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/121.0.0.0 Safari/537.36',
];

const SAME_NETWORK_NEWER_BROWSER = [
    'REMOTE_ADDR' => '203.0.113.99',
    'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/122.0.6261.94 Safari/537.36',
];

const DIFFERENT_DEVICE = [
    'REMOTE_ADDR' => '198.51.100.4',
    'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 14) Chrome/121.0.0.0 Mobile Safari/537.36',
];

/**
 * @return array{grant: ClientAccessGrant, token: string, dossier: Dossier, responsible: User}
 */
function portalGrantContext(?User $responsible = null): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $responsible ??= $owner;

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'responsible_user_id' => $responsible->id,
    ]);

    $result = resolve(CreateClientAccessGrantAction::class)->handle($dossier, $owner, 7);

    return [
        'grant' => $result['grant'],
        'token' => $result['plain_text_token'],
        'dossier' => $dossier,
        'responsible' => $responsible,
    ];
}

test('the first redemption records the device without alerting anyone', function (): void {
    Notification::fake();

    ['grant' => $grant, 'token' => $token] = portalGrantContext();

    $this->withServerVariables(FIRST_DEVICE)
        ->get(route('portal.exchange', $token))
        ->assertRedirect(route('portal.show'));

    $grant->refresh();

    expect($grant->device_fingerprint)->not->toBeNull()
        ->and($grant->new_device_seen_at)->toBeNull();

    Notification::assertNothingSent();
});

test('reopening from the same device stays quiet', function (): void {
    Notification::fake();

    ['grant' => $grant, 'token' => $token] = portalGrantContext();

    $this->withServerVariables(FIRST_DEVICE)->get(route('portal.exchange', $token));
    $original = $grant->refresh()->device_fingerprint;

    $this->withServerVariables(FIRST_DEVICE)
        ->get(route('portal.exchange', $token))
        ->assertRedirect(route('portal.show'));

    expect($grant->refresh()->device_fingerprint)->toBe($original)
        ->and($grant->new_device_seen_at)->toBeNull();

    Notification::assertNothingSent();
});

/**
 * The reason the fingerprint is coarse. A precise one would fire on every
 * browser auto-update and every DHCP lease, and the alert would be ignored.
 */
test('a browser update on the same network is still the same device', function (): void {
    Notification::fake();

    ['grant' => $grant, 'token' => $token] = portalGrantContext();

    $this->withServerVariables(FIRST_DEVICE)->get(route('portal.exchange', $token));

    $this->withServerVariables(SAME_NETWORK_NEWER_BROWSER)
        ->get(route('portal.exchange', $token))
        ->assertRedirect(route('portal.show'));

    expect($grant->refresh()->new_device_seen_at)->toBeNull();

    Notification::assertNothingSent();
});

test('a genuinely different device lets the client in and tells the responsible member', function (): void {
    Notification::fake();

    ['grant' => $grant, 'token' => $token, 'responsible' => $responsible, 'dossier' => $dossier] = portalGrantContext();
    $otherMember = workspaceMember($dossier->tenant, Role::Adviser);

    $this->withServerVariables(FIRST_DEVICE)->get(route('portal.exchange', $token));

    $this->withServerVariables(DIFFERENT_DEVICE)
        ->get(route('portal.exchange', $token))
        ->assertRedirect(route('portal.show'));

    expect($grant->refresh()->new_device_seen_at)->not->toBeNull();

    $activity = Activity::query()
        ->where('event', AuditEvent::ClientPortalAccessedFromNewDevice->value)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->subject_id)->toBe($grant->id)
        ->and($activity->getProperty('fingerprint_changed'))->toBeTrue()
        ->and($activity->getProperty('dossier_id'))->toBe($grant->dossier_id);

    // Responsible-only delivery: other active members stay quiet when a responsible user exists.
    Notification::assertSentTo($responsible, ClientPortalNewDeviceNotification::class);
    Notification::assertNotSentTo($otherMember, ClientPortalNewDeviceNotification::class);
    Notification::assertSentTimes(ClientPortalNewDeviceNotification::class, 1);
});

/**
 * The signal worth acting on is "this link is open in more than one place".
 * Repeating it every time a client switches phone and laptop would bury it.
 */
test('only one alert is raised per grant however many devices open it', function (): void {
    Notification::fake();

    ['token' => $token] = portalGrantContext();

    $this->withServerVariables(FIRST_DEVICE)->get(route('portal.exchange', $token));
    $this->withServerVariables(DIFFERENT_DEVICE)->get(route('portal.exchange', $token));
    $this->withServerVariables(FIRST_DEVICE)->get(route('portal.exchange', $token));
    $this->withServerVariables(DIFFERENT_DEVICE)->get(route('portal.exchange', $token));

    Notification::assertSentTimes(ClientPortalNewDeviceNotification::class, 1);

    expect(Activity::query()
        ->where('event', AuditEvent::ClientPortalAccessedFromNewDevice->value)
        ->count())->toBe(1);
});

test('every active member is told when a dossier has no responsible user', function (): void {
    Notification::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'responsible_user_id' => null,
    ]);

    $result = resolve(CreateClientAccessGrantAction::class)->handle($dossier, $owner, 7);

    $this->withServerVariables(FIRST_DEVICE)->get(route('portal.exchange', $result['plain_text_token']));
    $this->withServerVariables(DIFFERENT_DEVICE)->get(route('portal.exchange', $result['plain_text_token']));

    Notification::assertSentTo($owner, ClientPortalNewDeviceNotification::class);
});

test('block mode refuses an unfamiliar device as a 404', function (): void {
    Notification::fake();
    config(['portal.new_device_action' => NewPortalDeviceAction::Block->value]);

    ['grant' => $grant, 'token' => $token] = portalGrantContext();

    $this->withServerVariables(FIRST_DEVICE)
        ->get(route('portal.exchange', $token))
        ->assertRedirect(route('portal.show'));

    // A 404, not a 403: a probe must not be able to tell a blocked device from
    // a token that never existed.
    $this->withServerVariables(DIFFERENT_DEVICE)
        ->get(route('portal.exchange', $token))
        ->assertNotFound();

    expect(Activity::query()
        ->where('event', AuditEvent::ClientPortalNewDeviceBlocked->value)
        ->where('subject_id', $grant->id)
        ->exists())->toBeTrue();

    $blocked = Activity::query()
        ->where('event', AuditEvent::ClientPortalNewDeviceBlocked->value)
        ->where('subject_id', $grant->id)
        ->latest('id')
        ->first();

    expect($blocked?->getProperty('dossier_id'))->toBe($grant->dossier_id);

    Notification::assertNothingSent();
});

test('ignore mode records nothing at all', function (): void {
    Notification::fake();
    config(['portal.new_device_action' => NewPortalDeviceAction::Ignore->value]);

    ['grant' => $grant, 'token' => $token] = portalGrantContext();

    $this->withServerVariables(FIRST_DEVICE)->get(route('portal.exchange', $token));
    $this->withServerVariables(DIFFERENT_DEVICE)
        ->get(route('portal.exchange', $token))
        ->assertRedirect(route('portal.show'));

    $grant->refresh();

    expect($grant->device_fingerprint)->toBeNull()
        ->and($grant->new_device_seen_at)->toBeNull();

    Notification::assertNothingSent();
});

/**
 * A typo in the deploy environment must not silently switch the signal off.
 */
test('an unrecognised policy falls back to notifying', function (): void {
    Notification::fake();
    config(['portal.new_device_action' => 'notifyy']);

    ['token' => $token, 'responsible' => $responsible] = portalGrantContext();

    $this->withServerVariables(FIRST_DEVICE)->get(route('portal.exchange', $token));
    $this->withServerVariables(DIFFERENT_DEVICE)->get(route('portal.exchange', $token));

    Notification::assertSentTo($responsible, ClientPortalNewDeviceNotification::class);
});

test('the fingerprint stores no user agent or address', function (): void {
    $request = Request::create('/portal/access/token', server: FIRST_DEVICE);

    $fingerprint = PortalDeviceFingerprint::fromRequest($request);

    expect($fingerprint)->toMatch('/^[0-9a-f]{64}$/')
        ->and($fingerprint)->not->toContain('203.0.113')
        ->and($fingerprint)->not->toContain('Chrome');
});

/**
 * IpUtils::anonymize() strips an RFC 4007 scope. Without it the interface name
 * would be folded into the fingerprint, so the same client behind the same
 * network could look like a different device.
 */
test('an address scope is not part of the fingerprint', function (): void {
    $scoped = PortalDeviceFingerprint::fromRequest(Request::create(
        '/portal/access/token',
        server: ['REMOTE_ADDR' => 'fe80::1%eth0', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/121.0.0.0'],
    ));

    $unscoped = PortalDeviceFingerprint::fromRequest(Request::create(
        '/portal/access/token',
        server: ['REMOTE_ADDR' => 'fe80::1%eth1', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/121.0.0.0'],
    ));

    expect($scoped)->toBe($unscoped);
});

test('addresses in the same network share a fingerprint across IP versions', function (string $first, string $second): void {
    $agent = ['HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/121.0.0.0'];

    $a = PortalDeviceFingerprint::fromRequest(Request::create('/x', server: ['REMOTE_ADDR' => $first, ...$agent]));
    $b = PortalDeviceFingerprint::fromRequest(Request::create('/x', server: ['REMOTE_ADDR' => $second, ...$agent]));

    expect($a)->toBe($b);
})->with([
    'IPv4 within a /24' => ['203.0.113.10', '203.0.113.250'],
    'IPv6 within a /64' => ['2001:db8:1:2:aaaa::1', '2001:db8:1:2:ffff::9'],
]);

test('addresses in different networks do not share a fingerprint', function (): void {
    $agent = ['HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/121.0.0.0'];

    $a = PortalDeviceFingerprint::fromRequest(Request::create('/x', server: ['REMOTE_ADDR' => '203.0.113.10', ...$agent]));
    $b = PortalDeviceFingerprint::fromRequest(Request::create('/x', server: ['REMOTE_ADDR' => '198.51.100.10', ...$agent]));

    expect($a)->not->toBe($b);
});

test('a request with no user agent or address still yields a stable fingerprint', function (): void {
    $bare = PortalDeviceFingerprint::fromRequest(Request::create('/portal/access/token'));

    expect($bare)->toMatch('/^[0-9a-f]{64}$/')
        ->and($bare)->toBe(PortalDeviceFingerprint::fromRequest(Request::create('/portal/access/token')));
});

test('user agent and network each contribute to the fingerprint', function (): void {
    $base = ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/121.0.0.0'];

    $changedAgent = PortalDeviceFingerprint::fromRequest(Request::create('/x', server: [
        ...$base,
        'HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/120.0',
    ]));
    $changedNetwork = PortalDeviceFingerprint::fromRequest(Request::create('/x', server: [
        ...$base,
        'REMOTE_ADDR' => '198.51.100.10',
    ]));
    $original = PortalDeviceFingerprint::fromRequest(Request::create('/x', server: $base));

    expect($changedAgent)->not->toBe($original)
        ->and($changedNetwork)->not->toBe($original);
});

test('an empty stored fingerprint is treated as first use', function (): void {
    Notification::fake();

    ['grant' => $grant] = portalGrantContext();
    $grant->forceFill(['device_fingerprint' => ''])->save();

    $outcome = resolve(RegisterClientPortalDeviceAction::class)->handle(
        $grant,
        PortalDeviceFingerprint::fromRequest(Request::create('/x', server: FIRST_DEVICE)),
    );

    expect($outcome)->toBe(PortalDeviceOutcome::FirstUse)
        ->and($grant->refresh()->device_fingerprint)->not->toBeNull()
        ->and($grant->device_fingerprint)->not->toBe('');
});

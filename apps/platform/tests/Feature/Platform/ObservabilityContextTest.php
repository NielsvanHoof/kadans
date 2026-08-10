<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Shared\Observability\ObservabilityContext;
use App\Tenancy\Models\Tenant;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Log\ContextLogProcessor as ContextLogProcessorContract;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function (): void {
    Context::flush();
});

test('a web request stamps a correlation id and route name onto the context', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('profile.edit'));

    $response->assertOk();

    expect(Context::get(ObservabilityContext::RequestId))->toBeString()
        ->and(Context::get(ObservabilityContext::Route))->toBe('profile.edit')
        ->and($response->headers->get(ObservabilityContext::RequestIdHeader))
        ->toBe(Context::get(ObservabilityContext::RequestId));
});

test('a well-formed correlation id from the proxy is adopted', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeaders([ObservabilityContext::RequestIdHeader => '01JBX7Q9Z8-edge.eu-west-1'])
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertHeader(ObservabilityContext::RequestIdHeader, '01JBX7Q9Z8-edge.eu-west-1');

    expect(Context::get(ObservabilityContext::RequestId))->toBe('01JBX7Q9Z8-edge.eu-west-1');
});

/**
 * The inbound header is attacker-controlled and its value is written verbatim
 * into every log line and queue payload for the rest of the request.
 */
test('a malformed correlation id from a client is discarded', function (string $inbound): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withHeaders([ObservabilityContext::RequestIdHeader => $inbound])
        ->get(route('profile.edit'));

    $response->assertOk();

    expect(Context::get(ObservabilityContext::RequestId))->not->toBe($inbound)
        ->and(Context::get(ObservabilityContext::RequestId))->toMatch('/^[0-9A-Z]{26}$/');
})->with([
    'over the length cap' => [str_repeat('a', 65)],
    'whitespace' => ['has spaces'],
    'newline injection' => ["abc\nWARNING forged log line"],
    'json breakout' => ['abc","tenant_id":"99'],
    'empty' => [''],
]);

test('the current tenant is stamped and cleared as it changes', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    expect(Context::get(ObservabilityContext::TenantId))->toBe($tenant->id);

    Tenant::forgetCurrent();

    expect(Context::has(ObservabilityContext::TenantId))->toBeFalse();
});

test('the authenticated user id is stamped without any personal data', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();

    expect(Context::get(ObservabilityContext::UserId))->toBe($user->id)
        ->and(Context::all())->not->toContain($user->email)
        ->and(Context::all())->not->toContain($user->name);
});

/**
 * Driven through the dispatcher rather than `$this->artisan()`: the console
 * kernel skips `rerouteSymfonyCommandEvents()` when `runningUnitTests()`, so
 * CommandStarting never fires under the test harness. EventDiscoveryTest
 * covers the other half — that the listener is registered for the event.
 */
test('console runs are stamped with the command name and their own correlation id', function (): void {
    Event::dispatch(new CommandStarting(
        'retention:purge-legal',
        new ArrayInput([]),
        new NullOutput,
    ));

    expect(Context::get(ObservabilityContext::Command))->toBe('retention:purge-legal')
        ->and(Context::get(ObservabilityContext::RequestId))->toMatch('/^[0-9A-Z]{26}$/');
});

test('context is carried into the queue payload so jobs log under the same id', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    ObservabilityContext::rememberRequestId('01JBX7Q9Z8TESTREQUESTID');

    Queue::connection('database')->push('TestJobHandler@handle', ['id' => 1]);

    $payload = json_decode((string) DB::table('jobs')->value('payload'), associative: true);

    expect($payload)->toHaveKey('illuminate:log:context');

    // Context::dehydrate() serializes each value so models survive the round trip.
    $carried = $payload['illuminate:log:context']['data'];

    expect(unserialize($carried[ObservabilityContext::RequestId]))->toBe('01JBX7Q9Z8TESTREQUESTID')
        ->and(unserialize($carried[ObservabilityContext::TenantId]))->toBe($tenant->id);
});

/**
 * Guards the mechanism the other tests rely on: Laravel merges context into the
 * Monolog record's `extra`, which is what puts these fields in the log output.
 */
test('context reaches the log record', function (): void {
    ObservabilityContext::rememberRequestId('01JBX7Q9Z8TESTREQUESTID');
    ObservabilityContext::rememberTenant(42);

    $record = resolve(ContextLogProcessorContract::class)(new LogRecord(
        datetime: now()->toDateTimeImmutable(),
        channel: 'testing',
        level: Level::Info,
        message: 'Transcription: orchestrator starting audio processing.',
    ));

    expect($record->extra[ObservabilityContext::RequestId])->toBe('01JBX7Q9Z8TESTREQUESTID')
        ->and($record->extra[ObservabilityContext::TenantId])->toBe(42);
});

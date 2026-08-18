<?php

declare(strict_types=1);

use App\Shared\Observability\ObservabilityContext;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;

beforeEach(function (): void {
    Context::flush();
});

/**
 * Covers the deploy that never sets LOG_STACK. An operator who sets it
 * explicitly still wins — a file-only stack is rejected at boot instead, by
 * ProductionConfigurationValidator.
 */
test('production falls back to structured stderr when LOG_STACK is unset', function (): void {
    $logging = configFileWithEnv('logging', ['APP_ENV' => 'production', 'LOG_STACK' => null]);

    expect($logging['channels']['stack']['channels'])->toBe(['stderr_json', 'sentry_logs']);
});

test('local falls back to a readable file when LOG_STACK is unset', function (): void {
    $logging = configFileWithEnv('logging', ['APP_ENV' => 'local', 'LOG_STACK' => null]);

    expect($logging['channels']['stack']['channels'])->toBe(['single']);
});

test('an explicit LOG_STACK is respected over the environment default', function (): void {
    $logging = configFileWithEnv('logging', ['APP_ENV' => 'production', 'LOG_STACK' => 'stderr,papertrail']);

    expect($logging['channels']['stack']['channels'])->toBe(['stderr', 'papertrail']);
});

test('the stderr_json channel is JSON formatted', function (): void {
    $channel = config('logging.channels.stderr_json');

    expect($channel['driver'])->toBe('monolog')
        ->and($channel['handler'])->toBe(StreamHandler::class)
        ->and($channel['handler_with']['stream'])->toBe('php://stderr')
        ->and($channel['formatter'])->toBe(JsonFormatter::class);
});

/**
 * The point of B1 + B2 together: a log line written anywhere in the app comes
 * out as one JSON object carrying the correlation fields as queryable data.
 */
test('a log record is emitted as JSON carrying the request context as fields', function (): void {
    ObservabilityContext::rememberRequestId('01JBX7Q9Z8TESTREQUESTID');
    ObservabilityContext::rememberTenant(42);
    ObservabilityContext::rememberUser(7);
    ObservabilityContext::rememberRoute('workspaces.dossiers.show');

    $path = tempnam(sys_get_temp_dir(), 'structured-log-');

    // Same channel definition as stderr_json, pointed at a readable stream.
    $logger = Log::build([
        ...config('logging.channels.stderr_json'),
        'handler_with' => ['stream' => $path],
    ]);

    $logger->info('Transcription: orchestrator starting audio processing.', [
        'audio_recording_id' => 11,
    ]);

    $line = json_decode(mb_trim((string) file_get_contents($path)), associative: true);

    expect($line['message'])->toBe('Transcription: orchestrator starting audio processing.')
        ->and($line['level_name'])->toBe('INFO')
        ->and($line['context']['audio_recording_id'])->toBe(11)
        ->and($line['extra'][ObservabilityContext::RequestId])->toBe('01JBX7Q9Z8TESTREQUESTID')
        ->and($line['extra'][ObservabilityContext::TenantId])->toBe(42)
        ->and($line['extra'][ObservabilityContext::UserId])->toBe(7)
        ->and($line['extra'][ObservabilityContext::Route])->toBe('workspaces.dossiers.show');

    unlink($path);
});

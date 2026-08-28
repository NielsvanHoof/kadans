<?php

declare(strict_types=1);

use Illuminate\Contracts\Mail\Factory;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\SesTransport;

/**
 * Browser PUTs to Floci are cross-origin (http://localhost → :4566). The
 * bucket CORS rule in ready.d is what answers the OPTIONS preflight — without
 * it Safari/Chrome fail the signed upload with 403.
 */
test('the Floci bootstrap applies PUT cors for the Sail browser origin', function (): void {
    $script = (string) file_get_contents(base_path('docker/floci/init/ready.d/01-create-local-bucket.sh'));

    expect($script)
        ->toContain('put-bucket-cors')
        ->toContain('"AllowedMethods": ["PUT"]')
        ->toContain('"http://localhost"')
        ->toContain('"http://127.0.0.1"')
        ->toContain('"AllowedHeaders": ["content-type"]')
        ->toContain('"ExposeHeaders": ["ETag"]')
        ->not->toContain('"AllowedOrigins": ["*"]');
});

test('the Floci bootstrap records document S3 data events in CloudTrail', function (): void {
    $script = (string) file_get_contents(base_path('docker/floci/init/ready.d/01-create-local-bucket.sh'));

    expect($script)
        ->toContain('local-audit-logs')
        ->toContain('aws cloudtrail create-trail')
        ->toContain('aws cloudtrail put-event-selectors')
        ->toContain('\"Type\": \"AWS::S3::Object\"')
        ->toContain('arn:aws:s3:::${BUCKET}/')
        ->toContain('aws cloudtrail start-logging');
});

test('the Floci profile routes SES and Bedrock Runtime through the emulator', function (): void {
    $profile = (string) file_get_contents(base_path('env/profiles/floci.env'));
    $compose = (string) file_get_contents(base_path('compose.yaml'));

    expect($profile)
        ->toContain('MAIL_MAILER=ses')
        ->toContain('AWS_ENDPOINT_URL_BEDROCK_RUNTIME=http://floci:4566')
        ->and($compose)
        ->toContain('FLOCI_SERVICES_SES_SMTP_HOST: mailpit')
        ->toContain('FLOCI_SERVICES_SES_SMTP_PORT: 1025')
        ->toContain('FLOCI_SERVICES_CLOUDTRAIL_FLUSH_INTERVAL_SECONDS: 5');
});

test('Laravel builds its SES transport with the configured Floci endpoint', function (): void {
    config([
        'mail.mailers.ses' => ['transport' => 'ses'],
        'services.ses' => [
            'key' => 'sail',
            'secret' => 'password',
            'region' => 'eu-west-1',
            'endpoint' => 'http://floci:4566',
        ],
    ]);

    resolve(Factory::class)->purge('ses');

    $mailer = resolve(Factory::class)->mailer('ses');
    assert($mailer instanceof Mailer);

    $transport = $mailer->getSymfonyTransport();
    assert($transport instanceof SesTransport);

    expect((string) $transport->ses()->getEndpoint())->toBe('http://floci:4566');
});

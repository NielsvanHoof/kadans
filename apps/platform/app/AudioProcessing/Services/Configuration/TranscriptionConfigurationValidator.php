<?php

declare(strict_types=1);

namespace App\AudioProcessing\Services\Configuration;

use App\AudioProcessing\Enums\TranscriptionDriver;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use LogicException;

use function in_array;
use function is_array;
use function is_string;
use function mb_trim;

final readonly class TranscriptionConfigurationValidator
{
    public function __construct(
        private Application $application,
        private Repository $config,
    ) {}

    public function validate(): void
    {
        // Composer runs `package:discover` before .env exists. Laravel then
        // defaults APP_ENV to production, which must not fail the install.
        if ($this->isPackageDiscovery()) {
            return;
        }

        if (! $this->application->isProduction()) {
            return;
        }

        $configuredDriver = $this->config->get('transcription.driver');
        $driver = is_string($configuredDriver)
            ? TranscriptionDriver::tryFrom($configuredDriver)
            : null;

        throw_if($driver !== TranscriptionDriver::Ai, LogicException::class, 'Production transcription requires TRANSCRIPTION_DRIVER=ai.');

        $provider = $this->config->get('ai.default_for_transcription');

        throw_unless(is_string($provider) && mb_trim($provider) !== '', LogicException::class, 'Production transcription requires ai.default_for_transcription to be set.');

        $key = $this->config->get("ai.providers.{$provider}.key");

        throw_unless(is_string($key) && mb_trim($key) !== '', LogicException::class, "Production transcription requires an API key configured for the [{$provider}] provider.");
    }

    /**
     * True while Composer triggers `php artisan package:discover`.
     */
    private function isPackageDiscovery(): bool
    {
        if (! $this->application->runningInConsole()) {
            return false;
        }

        // Read live CLI argv — Request::server() is a request snapshot and misses Composer.
        $argv = $_SERVER['argv'] ?? [];

        return is_array($argv) && in_array('package:discover', $argv, true);
    }
}

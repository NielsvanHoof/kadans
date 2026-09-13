<?php

declare(strict_types=1);

namespace App\Platform\Ai;

use Illuminate\Support\Facades\Config;
use Laravel\Ai\Embeddings;

use function is_string;

/**
 * Floci's Bedrock InvokeModel stub is text-shaped, not Titan-shaped.
 *
 * Non-Anthropic models get `{"outputs":[{"text":"Floci stub response"}]}`.
 * Laravel AI then throws EmbeddingsCountMismatchException (0 vectors for N
 * inputs) and a portal upload 500s inside UploadedDocument::create().
 */
final readonly class FlociEmbeddings
{
    /**
     * Local Floci only. Never stub in testing/staging/production — those must
     * either fake in Pest/seeders or call real Titan.
     */
    public static function shouldStub(string $environment, mixed $endpoint): bool
    {
        if ($environment !== 'local') {
            return false;
        }

        return is_string($endpoint) && str_contains($endpoint, 'floci');
    }

    public static function stubIfUsingFloci(): void
    {
        if (! self::shouldStub(
            (string) app()->environment(),
            Config::get('ai.providers.bedrock.endpoint'),
        )) {
            return;
        }

        Embeddings::fake();
    }
}

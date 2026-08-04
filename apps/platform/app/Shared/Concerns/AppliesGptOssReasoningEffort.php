<?php

declare(strict_types=1);

namespace App\Shared\Concerns;

use Laravel\Ai\Enums\Lab;

/**
 * gpt-oss models on Bedrock can return only reasoning tokens and no answer
 * text unless reasoning effort is capped — the same failure mode
 * BedrockDocumentOverviewNormalizer already guards against for OCR.
 */
trait AppliesGptOssReasoningEffort
{
    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        if ($provider !== Lab::Bedrock && $provider !== 'bedrock') {
            return [];
        }

        $modelId = (string) config('ai.providers.bedrock.models.text.default');

        if (! str_contains($modelId, 'gpt-oss') && ! str_contains($modelId, 'openai.')) {
            return [];
        }

        return ['additionalModelRequestFields' => ['reasoning_effort' => 'low']];
    }
}

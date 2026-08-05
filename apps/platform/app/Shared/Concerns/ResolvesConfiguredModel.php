<?php

declare(strict_types=1);

namespace App\Shared\Concerns;

use function is_string;

/**
 * Resolves an agent's provider and model from its `ai.agents.*` config slot.
 *
 * Laravel\Ai's Promptable trait looks for `provider()` / `model()` methods before
 * falling back to the #[Provider] / #[Model] attributes, which is what makes this
 * work — attribute arguments must be constant expressions, so they cannot read
 * config. Defining them here keeps model selection out of the call sites.
 */
trait ResolvesConfiguredModel
{
    /**
     * The agent's key within the `ai.agents` config array.
     */
    abstract protected function agentConfigKey(): string;

    public function provider(): string
    {
        $provider = config("ai.agents.{$this->agentConfigKey()}.provider");

        return is_string($provider) && mb_trim($provider) !== ''
            ? $provider
            : (string) config('ai.default');
    }

    /**
     * A null model falls through to the provider's default text model.
     */
    public function model(): ?string
    {
        $model = config("ai.agents.{$this->agentConfigKey()}.model");

        return is_string($model) && mb_trim($model) !== '' ? $model : null;
    }
}

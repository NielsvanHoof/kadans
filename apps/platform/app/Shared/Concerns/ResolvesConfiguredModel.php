<?php

declare(strict_types=1);

namespace App\Shared\Concerns;

use Illuminate\Support\Facades\Config;

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
        // Blank/null agent providers fall back to ai.default (tests cover null).
        $provider = Config::get("ai.agents.{$this->agentConfigKey()}.provider");

        return is_string($provider) && mb_trim($provider) !== ''
            ? $provider
            : Config::string('ai.default');
    }

    /**
     * A null model falls through to the provider's default text model.
     */
    public function model(): ?string
    {
        // Some agents leave model unset (env without default) so Config::get.
        $model = Config::get("ai.agents.{$this->agentConfigKey()}.model");

        return is_string($model) && mb_trim($model) !== '' ? $model : null;
    }
}

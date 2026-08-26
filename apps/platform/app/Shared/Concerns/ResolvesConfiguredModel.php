<?php

declare(strict_types=1);

namespace App\Shared\Concerns;

use Illuminate\Support\Facades\Config;

use function is_int;
use function is_string;

/**
 * Resolves an agent's provider, model and timeout from its `ai.agents.*` slot.
 *
 * Laravel\Ai's Promptable trait looks for `provider()` / `model()` methods before
 * falling back to the #[Provider] / #[Model] attributes, which is what makes this
 * work — attribute arguments must be constant expressions, so they cannot read
 * config. Defining them here keeps model selection out of the call sites.
 */
trait ResolvesConfiguredModel
{
    /**
     * Fallback for an agent whose slot does not set one.
     */
    private const int DEFAULT_AGENT_TIMEOUT_SECONDS = 30;

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

    /**
     * How long to wait on the provider before giving up.
     *
     * Promptable defaults to 60 seconds, which is far too long for the two
     * agents a controller calls inline: the app runs on Octane with a small
     * worker pool, so a hung provider call holds a worker that could be
     * serving pages, and OCTANE_MAX_EXECUTION_TIME would kill the worker
     * mid-request before the SDK ever gave up. A user waiting on a draft has
     * given up long before then anyway.
     *
     * Per agent and config-driven rather than a #[Timeout] attribute, for the
     * same reason provider() and model() are methods here: attribute arguments
     * must be constant expressions and cannot read config.
     */
    public function timeout(): int
    {
        // Config::get, not Config::integer: the key exists-as-null whenever a
        // slot omits it or the env var is set but empty, and Config::integer
        // throws on null while ignoring the default (.ai/rules/app.md).
        $timeout = Config::get("ai.agents.{$this->agentConfigKey()}.timeout");

        return is_int($timeout) && $timeout > 0
            ? $timeout
            : self::DEFAULT_AGENT_TIMEOUT_SECONDS;
    }
}

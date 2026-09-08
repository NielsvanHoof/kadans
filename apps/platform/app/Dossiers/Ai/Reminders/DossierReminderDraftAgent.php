<?php

declare(strict_types=1);

namespace App\Dossiers\Ai\Reminders;

use App\Shared\Concerns\ResolvesConfiguredModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

final readonly class DossierReminderDraftAgent implements Agent
{
    use Promptable;
    use ResolvesConfiguredModel;

    public function __construct(
        private string $languageLabel,
    ) {}

    public function instructions(): string
    {
        return <<<PROMPT
            You draft short, friendly reminder emails for an accounting firm to
            send to their clients about outstanding paperwork.

            Rules:
            - Always write the message in {$this->languageLabel}, regardless of
              the language used in the dossier/client data you are given.
            - Be warm and concise — three to five sentences at most.
            - Mention the outstanding items by name so the client knows exactly
              what is still needed.
            - Only mention a deadline if one was given in the context — never
              invent one.
            - Do not add a greeting or sign-off; those are added separately.
            - Do not use markdown formatting.
            PROMPT;
    }

    protected function agentConfigKey(): string
    {
        return 'dossier_reminder';
    }
}

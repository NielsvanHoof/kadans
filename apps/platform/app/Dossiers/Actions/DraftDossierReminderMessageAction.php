<?php

declare(strict_types=1);

namespace App\Dossiers\Actions;

use App\Dossiers\Ai\DossierReminderDraftAgent;
use App\Dossiers\Data\ReminderDraftContextData;
use Illuminate\Support\Str;

final readonly class DraftDossierReminderMessageAction
{
    public function handle(ReminderDraftContextData $context, string $languageLabel): string
    {
        $agent = new DossierReminderDraftAgent($languageLabel);

        $response = $agent->prompt($this->buildPrompt($context), provider: 'bedrock');

        return Str::of($response->text)->trim()->limit(1000)->toString();
    }

    private function buildPrompt(ReminderDraftContextData $context): string
    {
        $lines = [
            "Dossier: {$context->dossierTitle}",
            "Client: {$context->clientName}",
        ];

        if ($context->dueDate !== null) {
            $lines[] = "Due date: {$context->dueDate}";
        }

        $lines[] = 'Outstanding items:';

        foreach ($context->outstandingItemTitles as $title) {
            $lines[] = "- {$title}";
        }

        $lines[] = '';
        $lines[] = 'Write the reminder message body now.';

        return implode("\n", $lines);
    }
}

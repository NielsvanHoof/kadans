<?php

declare(strict_types=1);

use App\DocumentProcessing\Ai\DocumentOverviewAgent;
use App\Dossiers\Ai\DossierReminderDraftAgent;
use App\Questionnaires\Ai\ChecklistDraftAgent;
use Tests\TestCase;

pest()
    ->extend(TestCase::class);

test('agents resolve their provider from the ai.agents config slot', function (): void {
    expect((new DocumentOverviewAgent)->provider())->toBe('bedrock')
        ->and(new ChecklistDraftAgent('Dutch')->provider())->toBe('bedrock')
        ->and(new DossierReminderDraftAgent('Dutch')->provider())->toBe('bedrock');
});

test('a per-agent model override is applied', function (): void {
    config(['ai.agents.document_overview.model' => 'eu.anthropic.claude-opus-4-6-v1']);

    expect((new DocumentOverviewAgent)->model())->toBe('eu.anthropic.claude-opus-4-6-v1');
});

test('the OCR agent can use a different model than text generation agents', function (): void {
    config([
        'ai.agents.document_overview.model' => 'eu.anthropic.claude-sonnet-4-20250514-v1:0',
        'ai.agents.checklist_draft.model' => null,
    ]);

    // A null model falls through to the provider's models.text.default.
    expect((new DocumentOverviewAgent)->model())->toBe('eu.anthropic.claude-sonnet-4-20250514-v1:0')
        ->and(new ChecklistDraftAgent('Dutch')->model())->toBeNull();
});

test('a blank configured model falls back to the provider default', function (mixed $model): void {
    config(['ai.agents.document_overview.model' => $model]);

    expect((new DocumentOverviewAgent)->model())->toBeNull();
})->with([
    'null' => null,
    'empty string' => '',
    'whitespace only' => '   ',
]);

test('a blank configured provider falls back to the default provider', function (): void {
    config([
        'ai.default' => 'openai',
        'ai.agents.document_overview.provider' => null,
    ]);

    expect((new DocumentOverviewAgent)->provider())->toBe('openai');
});

/**
 * The assertions above only prove our getters. This one proves laravel/ai actually
 * consults them: Promptable checks for provider()/model() methods before falling
 * back to the #[Provider]/#[Model] attributes, and agent fakes bypass that path
 * entirely — so nothing else in the suite would catch a rename or a change in the
 * SDK's resolution order.
 */
function resolveProvidersAndModels(object $agent): array
{
    $method = new ReflectionMethod($agent, 'getProvidersAndModels');

    return $method->invoke($agent, null, null);
}

test('laravel/ai resolves the configured provider and model for a pinned agent', function (): void {
    config([
        'ai.agents.document_overview.provider' => 'bedrock',
        'ai.agents.document_overview.model' => 'eu.anthropic.claude-sonnet-4-20250514-v1:0',
    ]);

    expect(resolveProvidersAndModels(new DocumentOverviewAgent))
        ->toBe(['bedrock' => 'eu.anthropic.claude-sonnet-4-20250514-v1:0']);
});

test('an unpinned agent leaves the model unset so the provider default applies', function (): void {
    config([
        'ai.agents.checklist_draft.provider' => 'bedrock',
        'ai.agents.checklist_draft.model' => null,
        'ai.providers.bedrock.models.text.default' => 'eu.amazon.nova-lite-v1:0',
    ]);

    // The provider is resolved here; a null model is filled in downstream by the
    // SDK's getDefaultModelFor(), which reads the provider's models.text.default.
    expect(resolveProvidersAndModels(new ChecklistDraftAgent('Dutch')))
        ->toBe(['bedrock' => null]);
});

test('agents resolve their timeout from the ai.agents config slot', function (): void {
    config([
        'ai.agents.document_overview.timeout' => 45,
        'ai.agents.checklist_draft.timeout' => 30,
        'ai.agents.dossier_reminder.timeout' => 20,
    ]);

    expect((new DocumentOverviewAgent)->timeout())->toBe(45)
        ->and(new ChecklistDraftAgent('Dutch')->timeout())->toBe(30)
        ->and(new DossierReminderDraftAgent('Dutch')->timeout())->toBe(20);
});

test('an agent slot without a timeout falls back to the shared default', function (): void {
    config(['ai.agents.dossier_reminder.timeout' => null]);

    // Not laravel/ai's own 60s default: that is long enough for a hung provider
    // call to hold an Octane worker past OCTANE_MAX_EXECUTION_TIME.
    expect(new DossierReminderDraftAgent('Dutch')->timeout())->toBe(30);
});

/**
 * As with provider()/model() above: Promptable checks for a timeout() method
 * before falling back to #[Timeout] and then to its own 60s default, so this
 * proves the SDK actually reaches ours rather than just that the getter works.
 */
test('laravel/ai uses the configured timeout rather than its own default', function (): void {
    config(['ai.agents.dossier_reminder.timeout' => 20]);

    $agent = new DossierReminderDraftAgent('Dutch');
    $getTimeout = new ReflectionMethod($agent, 'getTimeout');

    expect($getTimeout->invoke($agent, null))->toBe(20)
        // An explicit per-call timeout still wins, which is the SDK's contract.
        ->and($getTimeout->invoke($agent, 5))->toBe(5);
});

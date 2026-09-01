<?php

declare(strict_types=1);

use App\DocumentProcessing\Ai\DocumentOverviewAgent;
use App\DocumentProcessing\Services\Ocr\Normalization\AgentDocumentOverviewNormalizer;
use App\Tenancy\Models\TenantSetting;
use Laravel\Ai\Prompts\AgentPrompt;

test('document overview normalizer skips the agent when the feature is disabled', function (): void {
    DocumentOverviewAgent::fake();

    ['tenant' => $tenant] = provisionWorkspace();

    TenantSetting::query()->where('tenant_id', $tenant->id)->update([
        'ai_document_overview_enabled' => false,
    ]);

    actingInWorkspace($tenant);

    $overview = resolve(AgentDocumentOverviewNormalizer::class)->describe([
        'document_type' => null,
        'summary' => null,
        'fields' => [
            ['label' => 'BSN', 'value' => '287505030', 'confidence' => 0.99, 'sensitivity' => null],
        ],
        'tables' => [],
        'notes' => [],
        'confidence' => null,
    ]);

    expect($overview)->toBe([
        'document_type' => null,
        'summary' => null,
        'notes' => [],
    ]);

    DocumentOverviewAgent::assertNeverPrompted();
});

test('document overview normalizer still prompts when the feature is enabled', function (): void {
    DocumentOverviewAgent::fake([[
        'document_type' => 'payslip',
        'summary' => 'January payslip',
        'notes' => [],
    ]]);

    ['tenant' => $tenant] = provisionWorkspace();

    actingInWorkspace($tenant);

    $overview = resolve(AgentDocumentOverviewNormalizer::class)->describe([
        'document_type' => null,
        'summary' => null,
        'fields' => [
            ['label' => 'BSN', 'value' => '287505030', 'confidence' => 0.99, 'sensitivity' => null],
        ],
        'tables' => [],
        'notes' => [],
        'confidence' => null,
    ]);

    expect($overview['document_type'])->toBe('payslip');

    DocumentOverviewAgent::assertPrompted(
        fn (AgentPrompt $prompt): bool => $prompt->contains('BSN: 287505030'),
    );
});

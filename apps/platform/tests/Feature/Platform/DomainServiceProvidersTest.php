<?php

declare(strict_types=1);

use App\Audit\AuditServiceProvider;
use App\Audit\Models\Activity;
use App\Audit\Policies\ActivityPolicy;
use App\ClientPortal\ClientPortalServiceProvider;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Policies\ClientAccessGrantPolicy;
use App\Clients\ClientsServiceProvider;
use App\Clients\Models\Client;
use App\Clients\Policies\ClientPolicy;
use App\DocumentProcessing\Contracts\Ocr\DocumentOcr;
use App\DocumentProcessing\Contracts\Ocr\DocumentOverviewNormalizer;
use App\DocumentProcessing\DocumentProcessingServiceProvider;
use App\DocumentProcessing\Services\Ocr\Drivers\MockOcrExtractor;
use App\DocumentProcessing\Services\Ocr\Normalization\AgentDocumentOverviewNormalizer;
use App\Dossiers\DossiersServiceProvider;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Policies\DocumentRequestPolicy;
use App\Dossiers\Policies\DossierPolicy;
use App\Dossiers\Policies\UploadedDocumentPolicy;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Policies\PipelinePolicy;
use App\Platform\Contracts\Production\ReadinessCheck;
use App\Platform\PlatformServiceProvider;
use App\Platform\Services\Production\InfrastructureReadinessCheck;
use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Questionnaires\Policies\QuestionnaireTemplatePolicy;
use App\Questionnaires\QuestionnairesServiceProvider;
use App\Tenancy\Models\TenantInvitation;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Models\TenantRole;
use App\Tenancy\Policies\TenantInvitationPolicy;
use App\Tenancy\Policies\TenantMembershipPolicy;
use App\Tenancy\Policies\TenantRolePolicy;
use App\Tenancy\Support\PermissionRegistry;
use App\Tenancy\TenancyServiceProvider;
use Aws\Sqs\SqsClient;
use Aws\Textract\TextractClient;
use Aws\TranscribeService\TranscribeServiceClient;
use Illuminate\Support\Facades\Gate;

test('registers domain service providers in the application', function (): void {
    expect(app()->getProviders(DocumentProcessingServiceProvider::class))->not->toBeEmpty()
        ->and(app()->getProviders(TenancyServiceProvider::class))->not->toBeEmpty()
        ->and(app()->getProviders(DossiersServiceProvider::class))->not->toBeEmpty()
        ->and(app()->getProviders(PlatformServiceProvider::class))->not->toBeEmpty()
        ->and(app()->getProviders(AuditServiceProvider::class))->not->toBeEmpty()
        ->and(app()->getProviders(ClientsServiceProvider::class))->not->toBeEmpty()
        ->and(app()->getProviders(ClientPortalServiceProvider::class))->not->toBeEmpty()
        ->and(app()->getProviders(QuestionnairesServiceProvider::class))->not->toBeEmpty();
});

test('binds OCR and AWS clients from DocumentProcessingServiceProvider', function (): void {
    expect(resolve(TextractClient::class))->toBeInstanceOf(TextractClient::class)
        ->and(resolve(SqsClient::class))->toBeInstanceOf(SqsClient::class)
        ->and(resolve(DocumentOverviewNormalizer::class))->toBeInstanceOf(AgentDocumentOverviewNormalizer::class)
        ->and(resolve(DocumentOcr::class))->toBeInstanceOf(MockOcrExtractor::class);
});

test('points Textract, SQS, and Transcribe clients at a configured local AWS endpoint', function (): void {
    config([
        'ocr.textract.endpoint' => 'http://floci:4566',
        'transcription.transcribe.endpoint' => 'http://floci:4566',
    ]);

    // Singletons capture config at first resolve — rebuild after endpoint is set.
    app()->forgetInstance(TextractClient::class);
    app()->forgetInstance(SqsClient::class);
    app()->forgetInstance(TranscribeServiceClient::class);

    expect((string) resolve(TextractClient::class)->getEndpoint())
        ->toBe('http://floci:4566')
        ->and((string) resolve(SqsClient::class)->getEndpoint())
        ->toBe('http://floci:4566')
        ->and((string) resolve(TranscribeServiceClient::class)->getEndpoint())
        ->toBe('http://floci:4566');
});

test('leaves AWS clients on regional endpoints when no local endpoint is configured', function (): void {
    config([
        'ocr.textract.endpoint' => null,
        'ocr.textract.region' => 'eu-west-1',
        'transcription.transcribe.endpoint' => null,
        'transcription.transcribe.region' => 'eu-west-1',
    ]);

    app()->forgetInstance(TextractClient::class);
    app()->forgetInstance(SqsClient::class);
    app()->forgetInstance(TranscribeServiceClient::class);

    expect((string) resolve(TextractClient::class)->getEndpoint())
        ->not->toContain('floci')
        ->and((string) resolve(SqsClient::class)->getEndpoint())
        ->not->toContain('floci')
        ->and((string) resolve(TranscribeServiceClient::class)->getEndpoint())
        ->not->toContain('floci');
});

test('binds tenancy and platform services from their domain providers', function (): void {
    expect(resolve(PermissionRegistry::class))->toBeInstanceOf(PermissionRegistry::class)
        ->and(resolve(ReadinessCheck::class))->toBeInstanceOf(InfrastructureReadinessCheck::class);
});

test('registers domain policies via domain service providers', function (): void {
    expect(Gate::getPolicyFor(new Activity))->toBeInstanceOf(ActivityPolicy::class)
        ->and(Gate::getPolicyFor(new Client))->toBeInstanceOf(ClientPolicy::class)
        ->and(Gate::getPolicyFor(new ClientAccessGrant))->toBeInstanceOf(ClientAccessGrantPolicy::class)
        ->and(Gate::getPolicyFor(new Pipeline))->toBeInstanceOf(PipelinePolicy::class)
        ->and(Gate::getPolicyFor(new DocumentRequest))->toBeInstanceOf(DocumentRequestPolicy::class)
        ->and(Gate::getPolicyFor(new Dossier))->toBeInstanceOf(DossierPolicy::class)
        ->and(Gate::getPolicyFor(new UploadedDocument))->toBeInstanceOf(UploadedDocumentPolicy::class)
        ->and(Gate::getPolicyFor(new QuestionnaireTemplate))->toBeInstanceOf(QuestionnaireTemplatePolicy::class)
        ->and(Gate::getPolicyFor(new TenantInvitation))->toBeInstanceOf(TenantInvitationPolicy::class)
        ->and(Gate::getPolicyFor(new TenantMembership))->toBeInstanceOf(TenantMembershipPolicy::class)
        ->and(Gate::getPolicyFor(new TenantRole))->toBeInstanceOf(TenantRolePolicy::class);
});

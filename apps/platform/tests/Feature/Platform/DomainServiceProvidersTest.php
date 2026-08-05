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
use App\DocumentProcessing\Contracts\Ocr\DescribesDocumentOverview;
use App\DocumentProcessing\Contracts\Ocr\StartsDocumentOcr;
use App\DocumentProcessing\DocumentProcessingServiceProvider;
use App\DocumentProcessing\Models\Pipeline;
use App\DocumentProcessing\Policies\PipelinePolicy;
use App\DocumentProcessing\Services\Ocr\Drivers\MockOcrExtractor;
use App\DocumentProcessing\Services\Ocr\Normalization\AgentDocumentOverviewNormalizer;
use App\Dossiers\DossiersServiceProvider;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Policies\DocumentRequestPolicy;
use App\Dossiers\Policies\DossierPolicy;
use App\Dossiers\Policies\UploadedDocumentPolicy;
use App\Platform\Contracts\Production\ChecksReadiness;
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
use Illuminate\Support\Facades\Gate;

it('registers domain service providers in the application', function (): void {
    expect(app()->getProviders(DocumentProcessingServiceProvider::class))->not->toBeEmpty()
        ->and(app()->getProviders(TenancyServiceProvider::class))->not->toBeEmpty()
        ->and(app()->getProviders(DossiersServiceProvider::class))->not->toBeEmpty()
        ->and(app()->getProviders(PlatformServiceProvider::class))->not->toBeEmpty()
        ->and(app()->getProviders(AuditServiceProvider::class))->not->toBeEmpty()
        ->and(app()->getProviders(ClientsServiceProvider::class))->not->toBeEmpty()
        ->and(app()->getProviders(ClientPortalServiceProvider::class))->not->toBeEmpty()
        ->and(app()->getProviders(QuestionnairesServiceProvider::class))->not->toBeEmpty();
});

it('binds OCR and AWS clients from DocumentProcessingServiceProvider', function (): void {
    expect(resolve(TextractClient::class))->toBeInstanceOf(TextractClient::class)
        ->and(resolve(SqsClient::class))->toBeInstanceOf(SqsClient::class)
        ->and(resolve(DescribesDocumentOverview::class))->toBeInstanceOf(AgentDocumentOverviewNormalizer::class)
        ->and(resolve(StartsDocumentOcr::class))->toBeInstanceOf(MockOcrExtractor::class);
});

it('binds tenancy and platform services from their domain providers', function (): void {
    expect(resolve(PermissionRegistry::class))->toBeInstanceOf(PermissionRegistry::class)
        ->and(resolve(ChecksReadiness::class))->toBeInstanceOf(InfrastructureReadinessCheck::class);
});

it('registers domain policies via domain service providers', function (): void {
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

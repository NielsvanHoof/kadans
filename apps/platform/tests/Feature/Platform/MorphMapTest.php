<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Audit\Models\Activity;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\DocumentProcessing\Models\Pipeline;
use App\DocumentProcessing\Models\PipelineStep;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Models\UploadedDocument;
use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Questionnaires\Models\QuestionnaireTemplateItem;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantInvitation;
use App\Tenancy\Models\TenantMembership;
use Database\Factories\ClientAccessGrantFactory;
use Database\Factories\ClientFactory;
use Database\Factories\DocumentRequestFactory;
use Database\Factories\DossierExportFactory;
use Database\Factories\DossierFactory;
use Database\Factories\PipelineFactory;
use Database\Factories\PipelineStepFactory;
use Database\Factories\QuestionnaireTemplateFactory;
use Database\Factories\QuestionnaireTemplateItemFactory;
use Database\Factories\TenantFactory;
use Database\Factories\TenantInvitationFactory;
use Database\Factories\TenantMembershipFactory;
use Database\Factories\UploadedDocumentFactory;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

test('application models use stable morph aliases', function (): void {
    /** @var array<string, class-string<Model>> $aliases */
    $aliases = [
        'activity' => Activity::class,
        'client' => Client::class,
        'client_access_grant' => ClientAccessGrant::class,
        'document_request' => DocumentRequest::class,
        'dossier' => Dossier::class,
        'dossier_export' => DossierExport::class,
        'pipeline' => Pipeline::class,
        'pipeline_step' => PipelineStep::class,
        'questionnaire_template' => QuestionnaireTemplate::class,
        'questionnaire_template_item' => QuestionnaireTemplateItem::class,
        'tenant' => Tenant::class,
        'tenant_invitation' => TenantInvitation::class,
        'tenant_membership' => TenantMembership::class,
        'uploaded_document' => UploadedDocument::class,
        'user' => User::class,
    ];

    foreach ($aliases as $alias => $modelClass) {
        expect(Relation::getMorphedModel($alias))->toBe($modelClass)
            ->and((new $modelClass)->getMorphClass())->toBe($alias);
    }
});

test('moved models resolve their explicit factories', function (): void {
    /** @var array<class-string<Model>, class-string<Factory<Model>>> $factories */
    $factories = [
        User::class => UserFactory::class,
        ClientAccessGrant::class => ClientAccessGrantFactory::class,
        Client::class => ClientFactory::class,
        Pipeline::class => PipelineFactory::class,
        PipelineStep::class => PipelineStepFactory::class,
        DocumentRequest::class => DocumentRequestFactory::class,
        Dossier::class => DossierFactory::class,
        DossierExport::class => DossierExportFactory::class,
        UploadedDocument::class => UploadedDocumentFactory::class,
        QuestionnaireTemplate::class => QuestionnaireTemplateFactory::class,
        QuestionnaireTemplateItem::class => QuestionnaireTemplateItemFactory::class,
        Tenant::class => TenantFactory::class,
        TenantInvitation::class => TenantInvitationFactory::class,
        TenantMembership::class => TenantMembershipFactory::class,
    ];

    foreach ($factories as $modelClass => $factoryClass) {
        expect($modelClass::factory())->toBeInstanceOf($factoryClass);
    }
});

<?php

declare(strict_types=1);

use App\Audit\AuditServiceProvider;
use App\ClientPortal\ClientPortalServiceProvider;
use App\Clients\ClientsServiceProvider;
use App\DocumentProcessing\DocumentProcessingServiceProvider;
use App\Dossiers\DossiersServiceProvider;
use App\Platform\PlatformServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HealthServiceProvider;
use App\Providers\TypeScriptTransformerServiceProvider;
use App\Questionnaires\QuestionnairesServiceProvider;
use App\Reporting\ReportingServiceProvider;
use App\Tenancy\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HealthServiceProvider::class,
    TypeScriptTransformerServiceProvider::class,
    PlatformServiceProvider::class,
    TenancyServiceProvider::class,
    DocumentProcessingServiceProvider::class,
    DossiersServiceProvider::class,
    AuditServiceProvider::class,
    ClientsServiceProvider::class,
    ClientPortalServiceProvider::class,
    QuestionnairesServiceProvider::class,
    ReportingServiceProvider::class,
];

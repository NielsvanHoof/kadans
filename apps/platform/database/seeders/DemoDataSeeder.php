<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Accounts\Models\User;
use App\ClientPortal\Actions\CreateClientAccessGrantAction;
use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\DocumentProcessing\Services\Ocr\Drivers\DemoExtractionSamples;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineStepKey;
use App\Pipelines\Enums\PipelineStepStatus;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Models\PipelineStep;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Tenancy\Actions\ProvisionTenantAction;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Facades\Activity;

use function count;
use function in_array;
use function setPermissionsTeamId;
use function sprintf;

/**
 * Demo firm for live walkthroughs. Local only — wired through LocalDatabaseSeeder.
 *
 * Three things here are load-bearing for the demo and easy to lose in a refactor:
 *
 * 1. Everything runs inside TenantContext::runForTenant(). TenantScope resolves to
 *    `where 0 = 1` with no current tenant, so a seeder that forgets this appears to
 *    succeed and then shows nothing.
 * 2. answered_at is spread across the full eight weeks FetchDashboardTrendQuery
 *    buckets. Data created "now" renders as seven zero columns and one spike, which
 *    looks worse on screen than an empty chart.
 * 3. Model events stay on, and writes are wrapped in Activity::defaultCauser(), so the
 *    activity log fills with named actors instead of blank ones. Do not add
 *    WithoutModelEvents here.
 */
final class DemoDataSeeder extends Seeder
{
    private const string PASSWORD = 'QomposeDemo2026!';

    private const int TREND_WEEKS = 8;

    /**
     * Faker is seeded so every rehearsal produces identical data. A demo you
     * practised on different data is a demo you have not practised.
     */
    private const int FAKER_SEED = 20_260_828;

    /** One-time plain token for the demo portal link, captured for the summary table. */
    private ?string $portalToken = null;

    private ?string $portalDossierTitle = null;

    public function run(): void
    {
        fake()->seed(self::FAKER_SEED);

        $owner = $this->createStaffUser('Sanne Biemans', 'sanne@qompose-demo.nl');
        $adviser = $this->createStaffUser('Martijn Bakker', 'martijn@qompose-demo.nl');
        $reviewer = $this->createStaffUser('Lotte Jansen', 'lotte@qompose-demo.nl');

        $tenant = resolve(ProvisionTenantAction::class)->handle(
            name: 'Biemans Accountants',
            owner: $owner,
            slug: 'biemans-accountants',
        );

        $this->addMember($tenant, $adviser, Role::Adviser);
        $this->addMember($tenant, $reviewer, Role::Reviewer);

        resolve(TenantContext::class)->runForTenant($tenant, function () use ($tenant, $owner, $adviser, $reviewer): void {
            setPermissionsTeamId($tenant->getKey());

            Activity::defaultCauser($adviser, function () use ($owner, $adviser, $reviewer): void {
                $clients = $this->createClients();

                $this->createHistoricalDossiers($clients, $adviser);
                $this->createActiveDossiers($clients, $owner, $adviser, $reviewer);
            });
        });

        $this->printSummary($tenant, $owner);
    }

    private function createStaffUser(string $name, string $email): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => now(),
        ]);
    }

    private function addMember(Tenant $tenant, User $user, Role $role): void
    {
        TenantMembership::query()->create([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $user->getKey(),
            'status' => TenantMembershipStatus::Active,
            'joined_at' => now()->subMonths(4),
            'last_accessed_at' => now()->subDays(1),
        ]);

        setPermissionsTeamId($tenant->getKey());
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');
        $user->assignRole($role->value);
    }

    /**
     * @return list<Client>
     */
    private function createClients(): array
    {
        $names = [
            ['Bakkerij Van Dijk B.V.', 'administratie@bakkerijvandijk.nl'],
            ['Groenewoud Installatietechniek', 'finance@groenewoud-it.nl'],
            ['De Zeeuwse Vishandel V.O.F.', 'kantoor@zeeuwsevishandel.nl'],
            ['Hoekstra Transport B.V.', 'boekhouding@hoekstratransport.nl'],
            ['Studio Lumen', 'hello@studiolumen.nl'],
            ['Van Loon Advocaten', 'office@vanloonadvocaten.nl'],
            ['Tandtechniek Meijer B.V.', 'admin@tandtechniekmeijer.nl'],
            ['Duinzicht Vastgoed B.V.', 'beheer@duinzichtvastgoed.nl'],
            ['Praktijk Wielenga', 'praktijk@wielenga-zorg.nl'],
            ['J. de Boer', 'j.deboer@example.nl'],
            ['A. el Amrani', 'a.elamrani@example.nl'],
            ['M. Visser', 'm.visser@example.nl'],
            ['R. Postma', 'r.postma@example.nl'],
            ['S. Chatterjee', 's.chatterjee@example.nl'],
        ];

        $clients = [];

        foreach ($names as $index => [$name, $email]) {
            $clients[] = Client::query()->create([
                'name' => $name,
                'email' => $email,
                'created_at' => now()->subMonths(6)->addDays($index * 4),
                'updated_at' => now()->subMonths(6)->addDays($index * 4),
            ]);
        }

        return $clients;
    }

    /**
     * Completed dossiers going back eight weeks, so the throughput trend has a
     * believable shape rather than a single spike in the current week.
     *
     * @param  list<Client>  $clients
     */
    private function createHistoricalDossiers(array $clients, User $adviser): void
    {
        // Varying weekly volume: a flat line reads as fabricated.
        $perWeek = [6, 9, 7, 12, 8, 14, 11, 9];

        foreach ($perWeek as $weekOffset => $answeredCount) {
            $weekStart = CarbonImmutable::today()
                ->startOfWeek()
                ->subWeeks(self::TREND_WEEKS - 1 - $weekOffset);

            $client = $clients[$weekOffset % count($clients)];

            $dossier = Dossier::query()->create([
                'client_id' => $client->getKey(),
                'title' => $this->historicalTitle($weekOffset),
                'reference' => sprintf('DOS-2026-%03d', 100 + $weekOffset),
                'status' => DossierStatus::Completed,
                'responsible_user_id' => $adviser->getKey(),
                'due_date' => $weekStart->addDays(5),
                'created_at' => $weekStart->subDays(6),
                'updated_at' => $weekStart->addDays(6),
            ]);

            foreach (range(1, $answeredCount) as $item) {
                $answeredAt = $weekStart->addDays($item % 5)->addHours(9 + ($item % 7));

                DocumentRequest::query()->create([
                    'dossier_id' => $dossier->getKey(),
                    'type' => QuestionnaireItemType::File,
                    'title' => $this->requestTitle($item),
                    'status' => DocumentRequestStatus::Accepted,
                    'sort_order' => $item,
                    'answered_at' => $answeredAt,
                    'reviewed_by' => $adviser->getKey(),
                    'reviewed_at' => $answeredAt->addHours(3),
                    'created_at' => $weekStart->subDays(6),
                    'updated_at' => $answeredAt->addHours(3),
                ]);
            }
        }
    }

    private function historicalTitle(int $index): string
    {
        $titles = [
            'Jaarrekening 2025',
            'Aangifte inkomstenbelasting 2025',
            'BTW-aangifte Q1 2026',
            'Loonadministratie mei 2026',
            'Jaarrekening 2025',
            'BTW-aangifte Q2 2026',
            'Aangifte vennootschapsbelasting 2025',
            'Loonadministratie juni 2026',
        ];

        return $titles[$index % count($titles)];
    }

    private function requestTitle(int $index): string
    {
        $titles = [
            'Bankafschriften',
            'Facturen crediteuren',
            'Facturen debiteuren',
            'Loonstroken',
            'Kilometeradministratie',
            'Kassabonnen',
            'Voorraadlijst per 31-12',
            'Leasecontracten',
            'Verzekeringspolissen',
            'Hypotheekoverzicht',
            'Jaaropgave',
            'Pensioenoverzicht',
            'Aanslagen Belastingdienst',
            'Bankmutaties spaarrekening',
        ];

        return $titles[$index % count($titles)];
    }

    /**
     * The dossiers the demo actually walks through. Each one exists to make a
     * specific screen non-empty: the review queue, follow-up signals, the
     * builder's blank canvas, the extraction viewer.
     *
     * @param  list<Client>  $clients
     */
    private function createActiveDossiers(array $clients, User $owner, User $adviser, User $reviewer): void
    {
        // Blank canvas, so the drag-and-drop builder can be demoed from scratch.
        $this->makeDossier(
            client: $clients[4],
            title: 'Jaarrekening 2026 — opzet',
            reference: 'DOS-2026-201',
            status: DossierStatus::Draft,
            responsible: $adviser,
            dueInDays: 21,
            items: [],
        );

        $this->makeDossier(
            client: $clients[7],
            title: 'BTW-aangifte Q3 2026',
            reference: 'DOS-2026-202',
            status: DossierStatus::Draft,
            responsible: $owner,
            dueInDays: 30,
            items: [
                ['Facturen crediteuren Q3', QuestionnaireItemType::File, DocumentRequestStatus::Pending],
                ['Facturen debiteuren Q3', QuestionnaireItemType::File, DocumentRequestStatus::Pending],
            ],
        );

        // Overdue — drives Follow-up Signals on the dashboard.
        $this->makeDossier(
            client: $clients[0],
            title: 'Jaarrekening 2025',
            reference: 'DOS-2026-203',
            status: DossierStatus::AwaitingClient,
            responsible: $adviser,
            dueInDays: -9,
            items: [
                ['Bankafschriften 2025', QuestionnaireItemType::File, DocumentRequestStatus::Pending],
                ['Voorraadlijst per 31-12-2025', QuestionnaireItemType::File, DocumentRequestStatus::Pending],
                ['Heeft u een auto van de zaak?', QuestionnaireItemType::Boolean, DocumentRequestStatus::Pending],
                ['Toelichting bijzondere posten', QuestionnaireItemType::Textarea, DocumentRequestStatus::Pending],
            ],
        );

        $this->makeDossier(
            client: $clients[2],
            title: 'Aangifte inkomstenbelasting 2025',
            reference: 'DOS-2026-204',
            status: DossierStatus::AwaitingClient,
            responsible: $reviewer,
            dueInDays: -3,
            items: [
                ['Jaaropgave 2025', QuestionnaireItemType::File, DocumentRequestStatus::Pending],
                ['Hypotheekoverzicht 2025', QuestionnaireItemType::File, DocumentRequestStatus::Pending],
                ['IBAN voor teruggave', QuestionnaireItemType::Text, DocumentRequestStatus::Pending],
            ],
        );

        // The dossier the portal link points at: partly answered, so the client
        // side shows real progress rather than an untouched checklist.
        $portalDossier = $this->makeDossier(
            client: $clients[1],
            title: 'Loonadministratie augustus 2026',
            reference: 'DOS-2026-205',
            status: DossierStatus::AwaitingClient,
            responsible: $adviser,
            dueInDays: 6,
            items: [
                ['Loonstroken augustus', QuestionnaireItemType::File, DocumentRequestStatus::Accepted],
                ['Urenregistratie augustus', QuestionnaireItemType::File, DocumentRequestStatus::Submitted],
                ['Zijn er nieuwe medewerkers in dienst getreden?', QuestionnaireItemType::Boolean, DocumentRequestStatus::Pending],
                ['Kilometeradministratie augustus', QuestionnaireItemType::File, DocumentRequestStatus::Pending],
            ],
        );

        $this->makeDossier(
            client: $clients[9],
            title: 'Aangifte inkomstenbelasting 2025',
            reference: 'DOS-2026-206',
            status: DossierStatus::AwaitingClient,
            responsible: $adviser,
            dueInDays: 12,
            items: [
                ['Jaaropgave 2025', QuestionnaireItemType::File, DocumentRequestStatus::Submitted],
                ['Pensioenoverzicht', QuestionnaireItemType::File, DocumentRequestStatus::Pending],
            ],
        );

        // Sits in the review queue with everything submitted and nothing judged yet.
        $reviewDossier = $this->makeDossier(
            client: $clients[3],
            title: 'Jaarrekening 2025',
            reference: 'DOS-2026-207',
            status: DossierStatus::InReview,
            responsible: $reviewer,
            dueInDays: 4,
            items: [
                ['Bankafschriften 2025', QuestionnaireItemType::File, DocumentRequestStatus::Submitted],
                ['Loonstroken december 2025', QuestionnaireItemType::File, DocumentRequestStatus::Submitted],
                ['Facturen crediteuren Q4', QuestionnaireItemType::File, DocumentRequestStatus::Submitted],
                ['Heeft u een auto van de zaak?', QuestionnaireItemType::Boolean, DocumentRequestStatus::Accepted],
            ],
        );

        // Carries a rejection, so "changes requested" is demonstrable.
        $this->makeDossier(
            client: $clients[5],
            title: 'BTW-aangifte Q2 2026',
            reference: 'DOS-2026-208',
            status: DossierStatus::InReview,
            responsible: $adviser,
            dueInDays: 2,
            items: [
                ['Facturen crediteuren Q2', QuestionnaireItemType::File, DocumentRequestStatus::Accepted],
                ['Facturen debiteuren Q2', QuestionnaireItemType::File, DocumentRequestStatus::Rejected],
                ['Bankafschriften Q2', QuestionnaireItemType::File, DocumentRequestStatus::Submitted],
            ],
            rejectionReason: 'De aangeleverde scan mist pagina 2 en 3. Kunt u het volledige document opnieuw uploaden?',
        );

        $this->makeDossier(
            client: $clients[6],
            title: 'Loonadministratie juli 2026',
            reference: 'DOS-2026-209',
            status: DossierStatus::InReview,
            responsible: $owner,
            dueInDays: 8,
            items: [
                ['Loonstroken juli', QuestionnaireItemType::File, DocumentRequestStatus::Submitted],
                ['Urenregistratie juli', QuestionnaireItemType::File, DocumentRequestStatus::Submitted],
            ],
        );

        $this->makeDossier(
            client: $clients[8],
            title: 'BTW-aangifte Q2 2026',
            reference: 'DOS-2026-210',
            status: DossierStatus::Completed,
            responsible: $reviewer,
            dueInDays: -15,
            items: [
                ['Facturen crediteuren Q2', QuestionnaireItemType::File, DocumentRequestStatus::Accepted],
                ['Bankafschriften Q2', QuestionnaireItemType::File, DocumentRequestStatus::Accepted],
            ],
        );

        $this->attachAudioRecording($reviewDossier, $adviser);
        $this->attachFailedProcessing($reviewDossier);
        $this->issuePortalGrant($portalDossier, $adviser);
    }

    /**
     * @param  list<array{0: string, 1: QuestionnaireItemType, 2: DocumentRequestStatus}>  $items
     */
    private function makeDossier(
        Client $client,
        string $title,
        string $reference,
        DossierStatus $status,
        User $responsible,
        int $dueInDays,
        array $items,
        ?string $rejectionReason = null,
    ): Dossier {
        $createdAt = CarbonImmutable::now()->subDays(max(14, abs($dueInDays) + 14));

        $dossier = Dossier::query()->create([
            'client_id' => $client->getKey(),
            'title' => $title,
            'reference' => $reference,
            'status' => $status,
            'responsible_user_id' => $responsible->getKey(),
            'due_date' => CarbonImmutable::today()->addDays($dueInDays),
            'reminder_interval_days' => $status === DossierStatus::AwaitingClient ? 7 : null,
            'last_client_opened_at' => $status === DossierStatus::Draft ? null : $createdAt->addDays(2),
            'created_at' => $createdAt,
            'updated_at' => CarbonImmutable::now()->subDays(1),
        ]);

        foreach ($items as $index => [$itemTitle, $type, $itemStatus]) {
            $answeredAt = $itemStatus === DocumentRequestStatus::Pending
                ? null
                : $createdAt->addDays(3 + $index)->addHours(10 + $index);

            $request = DocumentRequest::query()->create([
                'dossier_id' => $dossier->getKey(),
                'type' => $type,
                'title' => $itemTitle,
                'instructions' => $this->instructionsFor($type),
                'status' => $itemStatus,
                'sort_order' => $index,
                'answer_text' => $type === QuestionnaireItemType::Text && $answeredAt !== null
                    ? 'NL91 ABNA 0417 1643 00'
                    : ($type === QuestionnaireItemType::Textarea && $answeredAt !== null
                        ? 'De eenmalige bate in Q4 betreft de verkoop van de bestelbus.'
                        : null),
                'answer_boolean' => $type === QuestionnaireItemType::Boolean && $answeredAt !== null,
                'answered_at' => $answeredAt,
                'reviewed_by' => in_array($itemStatus, [DocumentRequestStatus::Accepted, DocumentRequestStatus::Rejected], true)
                    ? $responsible->getKey()
                    : null,
                'reviewed_at' => in_array($itemStatus, [DocumentRequestStatus::Accepted, DocumentRequestStatus::Rejected], true)
                    ? $answeredAt?->addHours(5)
                    : null,
                'rejection_reason' => $itemStatus === DocumentRequestStatus::Rejected ? $rejectionReason : null,
                'created_at' => $createdAt,
                'updated_at' => $answeredAt ?? $createdAt,
            ]);

            if ($type === QuestionnaireItemType::File && $answeredAt !== null) {
                $this->attachUploadedDocument($request, $itemTitle, $answeredAt);
            }
        }

        return $dossier;
    }

    private function instructionsFor(QuestionnaireItemType $type): ?string
    {
        return match ($type) {
            QuestionnaireItemType::File => 'Upload een leesbare scan of PDF. Meerdere bestanden mogen.',
            QuestionnaireItemType::Boolean => 'Kies ja of nee.',
            QuestionnaireItemType::Textarea => 'Korte toelichting volstaat.',
            default => null,
        };
    }

    /**
     * Uploads land OCR-complete with a realistic Dutch extraction, and carry a
     * finished pipeline so the pipeline admin screen has runs to show.
     */
    private function attachUploadedDocument(DocumentRequest $request, string $title, CarbonImmutable $uploadedAt): void
    {
        $filename = mb_strtolower(str_replace([' ', '-'], '-', $title)).'.pdf';

        $document = UploadedDocument::query()->create([
            'document_request_id' => $request->getKey(),
            'disk' => 'local',
            'path' => sprintf('tenants/%d/dossiers/%d/%s', $request->tenant_id, $request->dossier_id, $filename),
            'original_filename' => $filename,
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(180_000, 3_400_000),
            'uploaded_at' => $uploadedAt,
            'processing_status' => DocumentProcessingStatus::Completed,
            'extracted_text' => $this->extractionFor($title),
            'processing_started_at' => $uploadedAt->addSeconds(4),
            'processing_finished_at' => $uploadedAt->addSeconds(37),
            'created_at' => $uploadedAt,
            'updated_at' => $uploadedAt->addSeconds(37),
        ]);

        $pipeline = Pipeline::query()->create([
            'type' => PipelineType::OcrDocument,
            'status' => PipelineStatus::Completed,
            'current_stage' => null,
            'provider' => 'textract',
            'pipelineable_type' => $document->getMorphClass(),
            'pipelineable_id' => $document->getKey(),
            'attempt_number' => 1,
            'started_at' => $uploadedAt->addSeconds(2),
            'last_activity_at' => $uploadedAt->addSeconds(37),
            'completed_at' => $uploadedAt->addSeconds(37),
            'created_at' => $uploadedAt->addSeconds(2),
            'updated_at' => $uploadedAt->addSeconds(37),
        ]);

        $steps = [
            [PipelineStepKey::Submit, 0, 2, 6],
            [PipelineStepKey::ProviderProcessing, 1, 6, 31],
            [PipelineStepKey::ProcessResults, 2, 31, 37],
        ];

        foreach ($steps as [$key, $position, $startOffset, $endOffset]) {
            PipelineStep::query()->create([
                'pipeline_id' => $pipeline->getKey(),
                'key' => $key,
                'position' => $position,
                'status' => PipelineStepStatus::Completed,
                'attempts' => 1,
                'started_at' => $uploadedAt->addSeconds($startOffset),
                'last_activity_at' => $uploadedAt->addSeconds($endOffset),
                'completed_at' => $uploadedAt->addSeconds($endOffset),
                'created_at' => $uploadedAt->addSeconds($startOffset),
                'updated_at' => $uploadedAt->addSeconds($endOffset),
            ]);
        }
    }

    /**
     * Shared with DemoOcrExtractor, so a document seeded before the demo and one
     * uploaded live during it produce the same kind of extraction.
     */
    private function extractionFor(string $title): string
    {
        return DemoExtractionSamples::forTitle($title);
    }

    /**
     * One genuinely failed OCR run. Without it Processing Health renders as an
     * empty "all healthy" card, which shows the operator nothing about what the
     * card is for and leaves the pipeline retry flow undemonstrable.
     */
    private function attachFailedProcessing(Dossier $dossier): void
    {
        $uploadedAt = CarbonImmutable::now()->subHours(20);

        $request = DocumentRequest::query()->create([
            'dossier_id' => $dossier->getKey(),
            'type' => QuestionnaireItemType::File,
            'title' => 'Leasecontract bestelbus',
            'instructions' => 'Upload een leesbare scan of PDF. Meerdere bestanden mogen.',
            'status' => DocumentRequestStatus::Submitted,
            'sort_order' => 90,
            'answered_at' => $uploadedAt,
            'created_at' => $uploadedAt,
            'updated_at' => $uploadedAt,
        ]);

        $document = UploadedDocument::query()->create([
            'document_request_id' => $request->getKey(),
            'disk' => 'local',
            'path' => sprintf('tenants/%d/dossiers/%d/leasecontract-bestelbus.pdf', $request->tenant_id, $dossier->getKey()),
            'original_filename' => 'leasecontract-bestelbus.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1_284_720,
            'uploaded_at' => $uploadedAt,
            'processing_status' => DocumentProcessingStatus::Failed,
            'processing_error' => 'Document is beveiligd met een wachtwoord en kon niet worden gelezen.',
            'processing_started_at' => $uploadedAt->addSeconds(3),
            'processing_finished_at' => $uploadedAt->addSeconds(22),
            'created_at' => $uploadedAt,
            'updated_at' => $uploadedAt->addSeconds(22),
        ]);

        $pipeline = Pipeline::query()->create([
            'type' => PipelineType::OcrDocument,
            'status' => PipelineStatus::Failed,
            'current_stage' => PipelineStepKey::ProcessResults,
            'provider' => 'textract',
            'pipelineable_type' => $document->getMorphClass(),
            'pipelineable_id' => $document->getKey(),
            'attempt_number' => 2,
            'error_code' => 'UnsupportedDocumentException',
            'error_message' => 'Textract could not process the document: the file is password protected.',
            'started_at' => $uploadedAt->addSeconds(3),
            'last_activity_at' => $uploadedAt->addSeconds(22),
            'failed_at' => $uploadedAt->addSeconds(22),
            'created_at' => $uploadedAt->addSeconds(3),
            'updated_at' => $uploadedAt->addSeconds(22),
        ]);

        $steps = [
            [PipelineStepKey::Submit, 0, PipelineStepStatus::Completed, 3, 8, null],
            [PipelineStepKey::ProviderProcessing, 1, PipelineStepStatus::Completed, 8, 19, null],
            [PipelineStepKey::ProcessResults, 2, PipelineStepStatus::Failed, 19, 22, 'UnsupportedDocumentException'],
        ];

        foreach ($steps as [$key, $position, $status, $startOffset, $endOffset, $errorCode]) {
            PipelineStep::query()->create([
                'pipeline_id' => $pipeline->getKey(),
                'key' => $key,
                'position' => $position,
                'status' => $status,
                'attempts' => $status === PipelineStepStatus::Failed ? 2 : 1,
                'error_code' => $errorCode,
                'error_message' => $errorCode === null ? null : 'The file is password protected.',
                'started_at' => $uploadedAt->addSeconds($startOffset),
                'last_activity_at' => $uploadedAt->addSeconds($endOffset),
                'completed_at' => $status === PipelineStepStatus::Completed ? $uploadedAt->addSeconds($endOffset) : null,
                'failed_at' => $status === PipelineStepStatus::Failed ? $uploadedAt->addSeconds($endOffset) : null,
                'created_at' => $uploadedAt->addSeconds($startOffset),
                'updated_at' => $uploadedAt->addSeconds($endOffset),
            ]);
        }
    }

    private function attachAudioRecording(Dossier $dossier, User $uploader): void
    {
        $uploadedAt = CarbonImmutable::now()->subDays(5)->setTime(14, 12);

        AudioRecording::query()->create([
            'dossier_id' => $dossier->getKey(),
            'disk' => 'local',
            'path' => sprintf('tenants/%d/audio/bespreking-jaarrekening.m4a', $dossier->tenant_id),
            'original_filename' => 'bespreking-jaarrekening-2025.m4a',
            'mime_type' => 'audio/mp4',
            'size_bytes' => 8_412_336,
            'uploaded_at' => $uploadedAt,
            'uploaded_by' => $uploader->getKey(),
            'transcription_status' => TranscriptionStatus::Completed,
            'transcript' => 'Goedemiddag, we lopen de jaarrekening 2025 even door. De omzet is licht gestegen ten opzichte van vorig jaar. De eenmalige bate in het vierde kwartaal betreft de verkoop van de bestelbus. Die boeken we apart, zodat het operationele resultaat vergelijkbaar blijft. De voorraadwaardering is per eenendertig december opgenomen conform de telling. Ik stuur de conceptcijfers deze week door.',
            'segments' => [
                ['text' => 'Goedemiddag, we lopen de jaarrekening 2025 even door.', 'speaker' => 'spk_0', 'start_seconds' => 0.0, 'end_seconds' => 4.2],
                ['text' => 'De omzet is licht gestegen ten opzichte van vorig jaar.', 'speaker' => 'spk_0', 'start_seconds' => 4.2, 'end_seconds' => 8.6],
                ['text' => 'De eenmalige bate in het vierde kwartaal betreft de verkoop van de bestelbus.', 'speaker' => 'spk_1', 'start_seconds' => 8.6, 'end_seconds' => 14.1],
                ['text' => 'Die boeken we apart, zodat het operationele resultaat vergelijkbaar blijft.', 'speaker' => 'spk_0', 'start_seconds' => 14.1, 'end_seconds' => 19.4],
                ['text' => 'De voorraadwaardering is per eenendertig december opgenomen conform de telling.', 'speaker' => 'spk_1', 'start_seconds' => 19.4, 'end_seconds' => 25.0],
                ['text' => 'Ik stuur de conceptcijfers deze week door.', 'speaker' => 'spk_0', 'start_seconds' => 25.0, 'end_seconds' => 28.3],
            ],
            'transcription_started_at' => $uploadedAt->addSeconds(6),
            'transcription_finished_at' => $uploadedAt->addSeconds(94),
            'created_at' => $uploadedAt,
            'updated_at' => $uploadedAt->addSeconds(94),
        ]);
    }

    private function issuePortalGrant(Dossier $dossier, User $createdBy): void
    {
        $result = resolve(CreateClientAccessGrantAction::class)->handle(
            dossier: $dossier,
            createdBy: $createdBy,
            expiresInDays: 30,
        );

        $this->portalToken = $result['plain_text_token'];
        $this->portalDossierTitle = $dossier->title;
    }

    private function printSummary(Tenant $tenant, User $owner): void
    {
        $workspaceUrl = route('workspaces.dashboard', ['tenant' => $tenant->slug]);
        $portalUrl = $this->portalToken === null
            ? '(none)'
            : route('portal.exchange', ['token' => $this->portalToken]);

        $this->command?->newLine();
        $this->command?->info('Demo firm seeded: '.$tenant->name);
        $this->command?->table(
            ['Field', 'Value'],
            [
                ['Sign in', $owner->email],
                ['Password', self::PASSWORD],
                ['Also available', 'martijn@qompose-demo.nl (adviser), lotte@qompose-demo.nl (reviewer)'],
                ['Workspace', $workspaceUrl],
                ['Portal link', $portalUrl],
                ['Portal dossier', $this->portalDossierTitle ?? '(none)'],
            ],
        );
        $this->command?->newLine();
    }
}

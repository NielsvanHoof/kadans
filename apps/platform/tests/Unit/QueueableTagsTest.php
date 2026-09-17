<?php

declare(strict_types=1);

use App\AudioProcessing\Notifications\AudioTranscriptionCompletedNotification;
use App\ClientPortal\Events\Outreach\ClientQuestionnaireCompleted;
use App\ClientPortal\Notifications\Outreach\ClientChangesRequestedNotification;
use App\ClientPortal\Notifications\Outreach\ClientQuestionnaireCompletedNotification;
use App\ClientPortal\Notifications\Session\ClientPortalNewDeviceNotification;
use App\DocumentProcessing\Jobs\Ocr\ProcessUploadedDocumentJob;
use App\Dossiers\Jobs\Exports\GenerateDossierExportJob;
use App\Pipelines\Events\PipelineUpdated;
use App\Tenancy\Notifications\Members\WorkspaceMemberInviteNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Date;

test('queue jobs expose telescope tags for their subjects', function (): void {
    expect(new ProcessUploadedDocumentJob(10, '/a.pdf', 20)->tags())->toBe([
        'ocr',
        'uploaded_document:10',
        'pipeline:20',
    ])->and(new GenerateDossierExportJob(33)->tags())->toBe([
        'export',
        'pipeline:33',
    ]);
});

test('broadcast events expose telescope tags for their subjects', function (): void {
    expect(new PipelineUpdated(
        tenantSlug: 'acme',
        pipelineId: 12,
        type: 'ocr_document',
        status: 'running',
        subjectType: 'uploaded_document',
        subjectId: 34,
        dossierId: 56,
    )->tags())->toContain('tenant:acme', 'pipeline:12', 'dossier:56')
        ->and(new ClientQuestionnaireCompleted(
            tenantSlug: 'acme',
            dossierId: 7,
            dossierTitle: 'Payroll',
            clientName: 'Jane',
            message: 'Done',
            dossierUrl: 'https://example.test/dossiers/7',
        )->tags())->toBe([
            'broadcast',
            'client.questionnaire.completed',
            'tenant:acme',
            'dossier:7',
        ]);
});

test('notifications expose telescope tags for their subjects', function (): void {
    expect(new ClientChangesRequestedNotification(
        documentRequestId: 4,
        dossierId: 9,
        clientName: 'Jane',
        dossierTitle: 'Payroll',
        documentRequestTitle: 'ID',
        firmName: 'Acme',
    )->tags())->toContain('document_request:4', 'dossier:9')
        ->and(new WorkspaceMemberInviteNotification(
            invitationId: 5,
            firmName: 'Acme',
            role: 'member',
            acceptUrl: 'https://example.test/invite',
            expiresAt: Date::now()->addDay(),
        )->tags())->toContain('invitation:5')
        ->and(new ClientPortalNewDeviceNotification(
            tenantId: 1,
            dossierId: 9,
            dossierTitle: 'Payroll',
            clientName: 'Jane',
            dossierUrl: 'https://example.test/dossiers/9',
        )->tags())->toBe([
            'notification',
            'client_portal_new_device',
            'tenant:1',
            'dossier:9',
        ]);
});

/*
 * The inverse of what this test used to assert. Queueing these two put the row
 * on `default` and the broadcast that announces it on `broadcasts` — separate
 * Horizon pools, drained in parallel — so the bell reloaded before the row
 * existed and staff had to refresh by hand. They are database-only, so sending
 * them inline costs one insert and restores the ordering. TASK-64.
 */
test('inbox notifications the bell reloads from Echo are not queued', function (): void {
    expect(new ClientQuestionnaireCompletedNotification(
        tenantId: 1,
        dossierId: 9,
        dossierTitle: 'Payroll',
        clientName: 'Jane',
        message: 'Done',
        dossierUrl: 'https://example.test/dossiers/9',
    ))->not->toBeInstanceOf(ShouldQueue::class)
        ->and(new AudioTranscriptionCompletedNotification(
            tenantId: 1,
            audioRecordingId: 3,
            originalFilename: 'call.mp3',
            dossierId: 9,
            dossierTitle: 'Payroll',
            audioRecordingUrl: 'https://example.test/audio/3',
        ))->not->toBeInstanceOf(ShouldQueue::class);
});

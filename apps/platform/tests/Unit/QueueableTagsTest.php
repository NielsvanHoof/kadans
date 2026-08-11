<?php

declare(strict_types=1);

use App\AudioProcessing\Notifications\AudioTranscriptionCompletedNotification;
use App\ClientPortal\Events\ClientQuestionnaireCompleted;
use App\ClientPortal\Notifications\ClientChangesRequestedNotification;
use App\ClientPortal\Notifications\ClientPortalNewDeviceNotification;
use App\ClientPortal\Notifications\ClientQuestionnaireCompletedNotification;
use App\DocumentProcessing\Jobs\ProcessUploadedDocumentJob;
use App\Dossiers\Jobs\GenerateDossierExportJob;
use App\Pipelines\Events\PipelineUpdated;
use App\Tenancy\Notifications\WorkspaceMemberInviteNotification;
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
    expect(new ClientQuestionnaireCompletedNotification(
        tenantId: 1,
        dossierId: 9,
        dossierTitle: 'Payroll',
        clientName: 'Jane',
        message: 'Done',
        dossierUrl: 'https://example.test/dossiers/9',
    )->tags())->toBe([
        'notification',
        'client_questionnaire_completed',
        'tenant:1',
        'dossier:9',
    ])->and(new ClientChangesRequestedNotification(
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
        ])->and(new AudioTranscriptionCompletedNotification(
            tenantId: 1,
            audioRecordingId: 3,
            originalFilename: 'call.mp3',
            dossierId: 9,
            dossierTitle: 'Payroll',
            audioRecordingUrl: 'https://example.test/audio/3',
        )->tags())->toBe([
            'notification',
            'audio_transcription_completed',
            'tenant:1',
            'audio_recording:3',
        ]);
});

test('client questionnaire completed notification is queued after commit', function (): void {
    $notification = new ClientQuestionnaireCompletedNotification(
        tenantId: 1,
        dossierId: 9,
        dossierTitle: 'Payroll',
        clientName: 'Jane',
        message: 'Done',
        dossierUrl: 'https://example.test/dossiers/9',
    );

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->afterCommit)->toBeTrue();
});

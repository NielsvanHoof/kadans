<?php

declare(strict_types=1);

use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Questionnaires\Enums\QuestionnaireItemType;
use Inertia\Testing\AssertableInertia as Assert;

test('a carried-forward answer is a suggestion and does not count as complete', function (): void {
    $context = createPortalDossierWithGrant();

    actingInWorkspace($context['tenant']);
    $documentRequest = DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Text,
        'title' => 'Registered address',
        'status' => DocumentRequestStatus::Pending,
        'answer_text' => '221B Baker Street',
        'carried_forward_at' => now(),
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->get(route('portal.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('portal/show')
            ->where('dossier.document_requests.0.id', $documentRequest->id)
            ->where('dossier.document_requests.0.is_carried_forward', true)
            ->where('dossier.document_requests.0.answer_text', '221B Baker Street')
            ->where('dossier.document_requests.0.can_respond', true)
            ->where('dossier.progress.total', 1)
            ->where('dossier.progress.completed', 0)
            ->where('dossier.progress.remaining', 1)
            ->where('dossier.progress.next_incomplete.id', $documentRequest->id));
});

test('confirming a carried-forward answer submits it for this period', function (): void {
    $context = createPortalDossierWithGrant();

    actingInWorkspace($context['tenant']);
    $documentRequest = DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Text,
        'title' => 'Registered address',
        'status' => DocumentRequestStatus::Pending,
        'answer_text' => '221B Baker Street',
        'carried_forward_at' => now(),
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->post(route('portal.document-requests.answer', [
        'documentRequest' => $documentRequest,
    ]), [
        'answer_text' => '221B Baker Street',
    ])->assertRedirect(route('portal.show'));

    expect($documentRequest->fresh())
        ->status->toBe(DocumentRequestStatus::Submitted)
        ->answer_text->toBe('221B Baker Street')
        ->carried_forward_at->toBeNull();

    $this->get(route('portal.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('dossier.document_requests.0.is_carried_forward', false)
            ->where('dossier.progress.completed', 1)
            ->where('dossier.progress.remaining', 0));
});

test('changing a carried-forward answer submits the new value and clears the stamp', function (): void {
    $context = createPortalDossierWithGrant();

    actingInWorkspace($context['tenant']);
    $documentRequest = DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Text,
        'title' => 'Registered address',
        'status' => DocumentRequestStatus::Pending,
        'answer_text' => '221B Baker Street',
        'carried_forward_at' => now(),
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->post(route('portal.document-requests.answer', [
        'documentRequest' => $documentRequest,
    ]), [
        'answer_text' => '10 Downing Street',
    ])->assertRedirect(route('portal.show'));

    expect($documentRequest->fresh())
        ->status->toBe(DocumentRequestStatus::Submitted)
        ->answer_text->toBe('10 Downing Street')
        ->carried_forward_at->toBeNull();
});

test('requests without a carried-forward stamp are unchanged on the portal', function (): void {
    $context = createPortalDossierWithGrant();

    actingInWorkspace($context['tenant']);
    $documentRequest = DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'type' => QuestionnaireItemType::Text,
        'title' => 'Registered address',
        'status' => DocumentRequestStatus::Pending,
        'answer_text' => null,
        'carried_forward_at' => null,
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->get(route('portal.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('dossier.document_requests.0.id', $documentRequest->id)
            ->where('dossier.document_requests.0.is_carried_forward', false)
            ->where('dossier.document_requests.0.answer_text', null)
            ->where('dossier.document_requests.0.can_respond', true)
            ->where('dossier.progress.remaining', 1));
});

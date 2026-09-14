<?php

declare(strict_types=1);

use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Questionnaires\Models\QuestionnaireTemplate;

/**
 * Scout DatabaseEngine ILIKEs every toSearchableArray key. Postgres rejects
 * bigint ILIKE, so integer `id` in the array turns a text search into a 500.
 * UploadedDocument already omits id; keep the other searchable models aligned.
 */
test('client scout columns omit integer id so postgres ilike cannot error', function (): void {
    $client = new Client([
        'name' => 'Acme BV',
        'email' => 'acme@example.com',
    ]);

    expect($client->toSearchableArray())->toBe([
        'name' => 'Acme BV',
        'email' => 'acme@example.com',
    ])->not->toHaveKey('id');
});

test('dossier scout columns omit integer id so postgres ilike cannot error', function (): void {
    $dossier = new Dossier([
        'title' => 'Annual review',
        'reference' => 'REF-1',
        'period_label' => '2026',
    ]);

    expect($dossier->toSearchableArray())
        ->toHaveKeys(['title', 'reference', 'period_label', 'clients.name'])
        ->not->toHaveKey('id');
});

test('document request scout columns omit integer id so postgres ilike cannot error', function (): void {
    $request = new DocumentRequest([
        'title' => 'Bank statement',
        'instructions' => 'Last three months',
    ]);

    expect($request->toSearchableArray())->toBe([
        'title' => 'Bank statement',
        'instructions' => 'Last three months',
    ])->not->toHaveKey('id');
});

test('activity scout columns omit integer id so postgres ilike cannot error', function (): void {
    $activity = new Activity([
        'description' => 'Client updated',
        'event' => 'updated',
    ]);

    expect($activity->toSearchableArray())->toBe([
        'description' => 'Client updated',
        'event' => 'updated',
    ])->not->toHaveKey('id');
});

test('questionnaire template scout columns omit integer id so postgres ilike cannot error', function (): void {
    $template = new QuestionnaireTemplate([
        'name' => 'Onboarding',
        'description' => 'Starter checklist',
    ]);

    expect($template->toSearchableArray())->toBe([
        'name' => 'Onboarding',
        'description' => 'Starter checklist',
    ])->not->toHaveKey('id');
});

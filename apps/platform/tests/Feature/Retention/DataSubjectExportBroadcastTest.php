<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Platform\Actions\DataSubject\GenerateDataSubjectExportAction;
use App\Platform\Actions\DataSubject\LogDataSubjectRequestAction;
use App\Platform\Enums\DataSubjectRequestType;
use App\Platform\Events\DataSubject\DataSubjectExportCompleted;
use Illuminate\Support\Facades\Event;

test('completing an export broadcasts to the workspace private channel', function (): void {
    Event::fake([DataSubjectExportCompleted::class]);

    ['tenant' => $tenant, 'owner' => $owner] = provisionWorkspace();
    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Export);

    resolve(GenerateDataSubjectExportAction::class)->handle($request, dataSubjectExportStaleCutoff());

    Event::assertDispatched(fn (DataSubjectExportCompleted $event): bool => $event->tenantSlug === $tenant->slug
        && $event->dataSubjectRequestId === $request->id
        && $event->broadcastAs() === 'data-subject.export.completed');
});

test('data subject export completed broadcasts on the workspace private channel', function (): void {
    $event = new DataSubjectExportCompleted(
        tenantSlug: 'acme',
        dataSubjectRequestId: 42,
    );

    expect($event->broadcastOn())->toHaveCount(1)
        ->and($event->broadcastOn()[0]->name)->toBe('private-workspaces.acme')
        ->and($event->broadcastWith())->toBe([
            'data_subject_request_id' => 42,
        ])
        ->and($event->tags())->toBe([
            'broadcast',
            'data-subject.export.completed',
            'tenant:acme',
            'data_subject_request:42',
        ]);
});

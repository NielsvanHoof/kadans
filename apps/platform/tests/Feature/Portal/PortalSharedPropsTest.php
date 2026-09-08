<?php

declare(strict_types=1);

use App\ClientPortal\Actions\Access\CreateClientAccessGrantAction;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\Dossier;
use Inertia\Testing\AssertableInertia as Assert;

test('portal shared props expose the firm name without the workspace slug', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'jane@example.com',
    ]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => '2025 Payroll dossier',
        'status' => DossierStatus::Draft,
    ]);

    $result = resolve(CreateClientAccessGrantAction::class)->handle($dossier, $owner, 7);

    $this->get(route('portal.exchange', $result['plain_text_token']))
        ->assertRedirect(route('portal.show'));

    $this->get(route('portal.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('portal/show')
            ->where('current_firm.name', $tenant->name)
            ->missing('current_firm.slug'));
});

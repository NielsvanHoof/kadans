<?php

declare(strict_types=1);

use App\Dossiers\Enums\DossierStatus;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Where a link lands, which is the whole visible difference between a
 * client-level link and the per-dossier one it grew out of.
 */

test('a client with several shared dossiers lands on a list of them', function (): void {
    $context = createPortalClientWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->get(route('portal.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('portal/index')
            ->has('waiting', 2)
            ->has('completed', 0)
            ->where('client.name', $context['client']->name));
});

test('the landing list separates what is still waiting from what is done', function (): void {
    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);
    $context['secondShared']->forceFill(['status' => DossierStatus::Completed])->save();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    // Both are reachable; only one still needs the client. The split is a
    // presentation decision, not a reachability one.
    $this->get(route('portal.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('portal/index')
            ->has('waiting', 1)
            ->has('completed', 1));
});

test('a client with one shared dossier lands straight on it', function (): void {
    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);
    $context['secondShared']->delete();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    // No list worth showing when there is no choice to make.
    $this->get(route('portal.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('portal/show')
            ->where('dossier.title', '2025 VAT return'));
});

test('a client with nothing shared sees a calm empty page, not an error', function (): void {
    $context = createPortalClientWithGrant();

    actingInWorkspace($context['tenant']);
    $context['shared']->delete();
    $context['secondShared']->delete();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    // The grant is valid and the visitor is legitimate — there is simply
    // nothing waiting. A 404 would hand a guest the app error page and its
    // "Go to login" button.
    $this->get(route('portal.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('portal/index')
            ->has('waiting', 0)
            ->has('completed', 0));
});

test('the dossier page offers the other dossiers the same link opens', function (): void {
    $context = createPortalClientWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->get(route('portal.dossiers.show', ['dossier' => $context['shared']->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('portal/show')
            ->has('other_dossiers', 1)
            ->where('other_dossiers.0.id', $context['secondShared']->id));
});

test('a dossier link renders its dossier at the same URL with no switcher', function (): void {
    $context = createPortalDossierWithGrant();

    $this->get(route('portal.exchange', $context['plainTextToken']));

    // Parity for every link already sitting in a client's inbox: same URL, same
    // page, and nothing new on it.
    $this->get(route('portal.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('portal/show')
            ->has('other_dossiers', 0)
            ->where('dossier.title', '2025 Payroll dossier'));
});

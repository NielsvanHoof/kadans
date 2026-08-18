<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Audit\Enums\AuditEvent;
use App\ClientPortal\Notifications\ClientPortalInviteNotification;
use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Questionnaires\Enums\QuestionnaireTemplateCategory;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

test('locale is resolved from the authenticated user preference', function (): void {
    $user = User::factory()->create(['locale' => 'nl']);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('locale', 'nl')
            ->where('translations.Settings', 'Instellingen'));
});

test('locale is resolved from the locale cookie when the user has no preference', function (): void {
    $user = User::factory()->create(['locale' => null]);

    $this->actingAs($user)
        ->withUnencryptedCookie('locale', 'nl')
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('locale', 'nl')
            ->where('translations.Dashboard', 'Dashboard'));
});

test('locale is resolved from the accept language header as a fallback', function (): void {
    $this->withHeaders(['Accept-Language' => 'nl-NL,nl;q=0.9,en;q=0.8'])
        ->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('locale', 'nl')
            ->where('translations.Save', 'Opslaan'));
});

test('available locales are shared with inertia', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('available_locales', [
                ['code' => 'en', 'label' => 'English'],
                ['code' => 'nl', 'label' => 'Nederlands'],
            ]));
});

test('authenticated users can update their preferred language', function (): void {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)
        ->from(route('language.edit'))
        ->patch(route('language.update'), ['locale' => 'nl'])
        ->assertRedirect(route('language.edit'))
        ->assertPlainCookie('locale', 'nl');

    expect($user->fresh()->locale)->toBe('nl');
});

test('locale update from another page redirects back instead of language settings', function (): void {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('language.update'), ['locale' => 'nl'])
        ->assertRedirect(route('profile.edit'))
        ->assertPlainCookie('locale', 'nl');

    expect($user->fresh()->locale)->toBe('nl');
});

test('language settings page can be rendered', function (): void {
    $this->withoutVite();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('language.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('settings/language'));
});

test('locale update requires a supported locale', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('language.update'), ['locale' => 'fr'])
        ->assertSessionHasErrors('locale');
});

test('questionnaire completed notification message is translated for dutch locale', function (): void {
    app()->setLocale('nl');

    expect(__(':client finished the questionnaire for “:dossier”.', [
        'client' => 'Jane Client',
        'dossier' => '2025 Payroll',
    ]))->toBe('Jane Client heeft de vragenlijst voor “2025 Payroll” afgerond.');
});

test('auth page translation keys are shared with inertia for guests', function (): void {
    $this->withHeaders(['Accept-Language' => 'nl-NL,nl;q=0.9'])
        ->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('locale', 'nl')
            ->where('translations.Log in', 'Inloggen')
            ->where('translations.Remember me', 'Onthoud mij')
            ->where('translations.Secure document exchange', 'Veilige documentuitwisseling'));
});

test('audit event labels are translated for the active locale', function (): void {
    app()->setLocale('nl');

    expect(AuditEvent::DossierViewed->label())->toBe('Dossier bekeken')
        ->and(AuditEvent::DocumentUploaded->label())->toBe('Document geüpload')
        ->and(AuditEvent::AccessDenied->label())->toBe('Toegang geweigerd')
        ->and(AuditEvent::DossierDeleted->label())->toBe('Dossier gearchiveerd')
        ->and(AuditEvent::ClientDeleted->label())->toBe('Klant gearchiveerd');
});

test('questionnaire template category labels are translated for the active locale', function (): void {
    app()->setLocale('nl');

    expect(QuestionnaireTemplateCategory::Jaarrekening->label())->toBe('Jaarrekening')
        ->and(QuestionnaireTemplateCategory::Fiscale->label())->toBe('Fiscale aangifte')
        ->and(QuestionnaireTemplateCategory::Custom->label())->toBe('Aangepast');
});

test('validation errors use translated sentences for dutch locale', function (): void {
    $user = User::factory()->create(['locale' => 'nl']);
    ['owner' => $user, 'tenant' => $tenant] = provisionWorkspace(owner: $user);

    $response = $this->actingAs($user)
        ->post(workspaceRoute('workspaces.clients.store', $tenant), [
            'name' => '',
            'email' => 'not-an-email',
        ]);

    $response->assertSessionHasErrors(['name', 'email']);

    expect($response->getSession()->get('errors')->get('name')[0])
        ->toBe('Naam is verplicht.')
        ->and($response->getSession()->get('errors')->get('email')[0])
        ->toBe('E-mailadres is geen geldig e-mailadres.');
});

test('fortify password reset status is translated for dutch locale', function (): void {
    app()->setLocale('nl');

    expect(__('passwords.sent'))
        ->toBe('We hebben je een link gestuurd om je wachtwoord te resetten.');
});

test('media and workspace translation keys are shared with inertia', function (): void {
    $user = User::factory()->create(['locale' => 'nl']);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('translations.All documents', 'Alle documenten')
            ->where('translations.Recent activity', 'Recente activiteit')
            ->where('translations.Create firm', 'Kantoor aanmaken')
            ->where('translations.Pending', 'In afwachting'));
});

test('client dashboard and questionnaire translation keys are shared with inertia', function (): void {
    $user = User::factory()->create(['locale' => 'nl']);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('translations.All clients', 'Alle klanten')
            ->where('translations.Create client', 'Klant aanmaken')
            ->where('translations.Review queue', 'Beoordelingswachtrij')
            ->where('translations.Awaiting client', 'Wacht op klant')
            ->where('translations.File upload', 'Bestandsupload')
            ->where('translations.Yes / no', 'Ja / nee')
            ->where('translations.Single choice', 'Enkele keuze')
            ->where('translations.Multiple choice', 'Meerkeuze')
            ->where('translations.Always show', 'Altijd tonen')
            ->where('translations.Show this field when…', 'Toon dit veld wanneer…')
            ->where('translations.Draft', 'Concept')
            ->where('translations.In review', 'In beoordeling'));
});

test('user preferred locale falls back to the app locale when unset', function (): void {
    $user = User::factory()->create(['locale' => null]);

    expect($user->preferredLocale())->toBe((string) config('app.locale'));

    $user->forceFill(['locale' => 'nl'])->save();

    expect($user->fresh()->preferredLocale())->toBe('nl');
});

test('portal invite notification is queued with the staff users locale', function (): void {
    Notification::fake();

    $owner = User::factory()->create(['locale' => 'nl']);
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace(owner: $owner);

    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'locale-client@example.com',
    ]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.dossiers.access-grants.store', $tenant, [
            'dossier' => $dossier,
        ]), [
            'expires_in_days' => 7,
            'send_invite' => true,
        ])
        ->assertRedirect();

    Notification::assertSentOnDemand(
        ClientPortalInviteNotification::class,
        function (ClientPortalInviteNotification $notification): bool {
            expect($notification->locale)->toBe('nl');

            return true;
        },
    );
});

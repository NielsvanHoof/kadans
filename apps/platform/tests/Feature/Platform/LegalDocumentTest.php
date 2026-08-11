<?php

declare(strict_types=1);

use App\Accounts\Enums\Locale;

test('guests can view each public legal document', function (string $document): void {
    $this->get(route('legal.show', ['document' => $document]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('legal/show')
            ->where('document', $document)
            ->where('is_draft', true)
            ->has('html')
            ->has('title')
            ->has('documents', 3));
})->with(['privacy', 'cookies', 'terms']);

test('unknown legal documents return not found', function (): void {
    $this->get('/legal/not-a-document')->assertNotFound();
});

test('legal document html is rendered from markdown for the request locale', function (): void {
    $this->withUnencryptedCookie('locale', Locale::Dutch->value)
        ->get(route('legal.show', ['document' => 'privacy']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('legal/show')
            ->where('locale', Locale::Dutch->value)
            ->where('document', 'privacy')
            ->where('html', fn (string $html): bool => str_contains($html, 'Privacybeleid')
                || str_contains($html, '<h1>')));
});

test('login page includes legal footer links', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/login'));
});

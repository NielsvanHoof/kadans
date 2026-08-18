<?php

declare(strict_types=1);

use Illuminate\Support\Facades\View;

test('invalid appearance cookies fall back to system before reaching the view', function (): void {
    $this->withUnencryptedCookie('appearance', 'evil\\payload<script>')
        ->get(route('login'))
        ->assertOk();

    expect(View::shared('appearance'))->toBe('system');
});

test('allowed appearance cookie values are shared with the view', function (string $appearance): void {
    $this->withUnencryptedCookie('appearance', $appearance)
        ->get(route('login'))
        ->assertOk();

    expect(View::shared('appearance'))->toBe($appearance);
})->with(['light', 'dark', 'system']);

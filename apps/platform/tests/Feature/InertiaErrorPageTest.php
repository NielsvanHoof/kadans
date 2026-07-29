<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

pest()
    ->use(RefreshDatabase::class);

test('missing pages render the inertia error page', function (): void {
    $this->get('/this-page-definitely-does-not-exist')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('error')
            ->where('status', 404));
});

test('forbidden responses render the inertia error page', function (): void {
    Route::middleware('web')->get('/__inertia-error-forbidden', function (): never {
        abort(403);
    });

    $this->get('/__inertia-error-forbidden')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('error')
            ->where('status', 403));
});

test('authenticated error pages still receive shared auth data', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/this-page-definitely-does-not-exist')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('error')
            ->where('status', 404)
            ->where('auth.user.id', $user->id));
});

test('json clients keep laravel json error payloads', function (): void {
    $this->getJson('/this-page-definitely-does-not-exist')
        ->assertNotFound()
        ->assertJsonStructure(['message'])
        ->assertJsonMissingPath('component');
});

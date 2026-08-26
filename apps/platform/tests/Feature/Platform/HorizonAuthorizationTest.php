<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use Illuminate\Support\Facades\Config;

test('guests cannot open the horizon dashboard outside local', function (): void {
    // The suite runs as `testing`, so Horizon's local bypass does not apply.
    $this->get('/horizon')->assertForbidden();
});

test('only allow-listed emails can open horizon outside local', function (): void {
    Config::set('horizon.allowed_emails', ['ops@qompose.test']);

    $allowed = User::factory()->create(['email' => 'ops@qompose.test']);
    $denied = User::factory()->create(['email' => 'other@qompose.test']);

    $this->actingAs($allowed)->get('/horizon')->assertOk();
    $this->actingAs($denied)->get('/horizon')->assertForbidden();
});

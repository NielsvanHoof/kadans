<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Vite;
use Laravel\Fortify\Features;
use Override;

abstract class TestCase extends BaseTestCase
{
    /**
     * Pin Vite to built-manifest mode for the whole suite.
     *
     * Vite decides it is "running hot" purely from the presence of `public/hot`,
     * which `npm run dev` creates. Without this, rendered markup — and anything
     * derived from it, such as the Content-Security-Policy — silently changes
     * shape depending on whether the developer happens to have the dev server
     * running. Tests that need hot behaviour mock Vite directly instead.
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        resolve(Vite::class)->useHotFile(
            $this->app->basePath('tests/vite-hot-file-intentionally-absent'),
        );
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}

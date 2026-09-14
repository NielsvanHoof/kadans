<?php

declare(strict_types=1);

namespace App\Accounts\Http\Controllers\Localization;

use App\Accounts\Actions\Localization\ResolveApplicationTimezoneAction;
use App\Accounts\Actions\Localization\UpdateUserLocaleAction;
use App\Accounts\Http\Requests\Localization\LocaleUpdateRequest;
use App\Accounts\Support\Localization\DisplayTimezones;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class LocaleController extends Controller
{
    public function __construct(
        private readonly ResolveApplicationTimezoneAction $resolveApplicationTimezone,
    ) {}

    /**
     * Show the user's language settings page.
     */
    public function edit(Request $request): Response
    {
        // The resolved zone itself is already a shared prop; only the selectable
        // list is page-scoped, so every other response stays free of it.
        return Inertia::render('settings/language', [
            'available_timezones' => DisplayTimezones::options(
                $this->resolveApplicationTimezone->handle($request),
            ),
        ]);
    }

    /**
     * Update the user's preferred language.
     */
    public function update(
        LocaleUpdateRequest $request,
        UpdateUserLocaleAction $updateUserLocale,
    ): RedirectResponse {
        $locale = (string) $request->validated('locale');

        $updateUserLocale->handle($request->authenticatedUser(), $locale);

        Toast::success(__('Language updated.'));

        return back(fallback: route('language.edit'))
            ->cookie('locale', $locale, 60 * 24 * 365);
    }
}

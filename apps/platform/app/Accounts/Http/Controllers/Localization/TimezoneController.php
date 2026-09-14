<?php

declare(strict_types=1);

namespace App\Accounts\Http\Controllers\Localization;

use App\Accounts\Actions\Localization\UpdateUserTimezoneAction;
use App\Accounts\Http\Requests\Localization\TimezoneUpdateRequest;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use Illuminate\Http\RedirectResponse;

final class TimezoneController extends Controller
{
    /**
     * Update the user's preferred timezone.
     *
     * The cookie is set alongside the column so the browser-detection path stays
     * consistent with the explicit choice — mirroring how the locale is stored.
     */
    public function update(
        TimezoneUpdateRequest $request,
        UpdateUserTimezoneAction $updateUserTimezone,
    ): RedirectResponse {
        $timezone = (string) $request->validated('timezone');

        $updateUserTimezone->handle($request->authenticatedUser(), $timezone);

        Toast::success(__('Timezone updated.'));

        return back(fallback: route('language.edit'))
            ->cookie('timezone', $timezone, 60 * 24 * 365);
    }
}

<?php

declare(strict_types=1);

namespace App\Accounts\Http\Controllers\Profile;

use App\Accounts\Actions\Profile\DeleteUserAccountAction;
use App\Accounts\Actions\Profile\UpdateUserProfileAction;
use App\Accounts\Http\Requests\Profile\ProfileDeleteRequest;
use App\Accounts\Http\Requests\Profile\ProfileUpdateRequest;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'emailVerified' => $request->user()?->hasVerifiedEmail() ?? false,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(
        ProfileUpdateRequest $request,
        UpdateUserProfileAction $updateUserProfile,
    ): RedirectResponse {
        $updateUserProfile->handle(
            $request->authenticatedUser(),
            $request->validated(),
        );

        Toast::success(__('Profile updated.'));

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(
        ProfileDeleteRequest $request,
        DeleteUserAccountAction $deleteUserAccount,
    ): RedirectResponse {
        $deleteUserAccount->handle($request->authenticatedUser());

        return redirect('/');
    }
}

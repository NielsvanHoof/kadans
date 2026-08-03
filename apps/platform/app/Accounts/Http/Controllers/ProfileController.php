<?php

declare(strict_types=1);

namespace App\Accounts\Http\Controllers;

use App\Accounts\Http\Requests\ProfileDeleteRequest;
use App\Accounts\Http\Requests\ProfileUpdateRequest;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->authenticatedUser();

        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Toast::success(__('Profile updated.'));

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request, LogAuditActivityAction $logAuditActivity): RedirectResponse
    {
        $user = $request->authenticatedUser();

        $logAuditActivity->handle(
            AuditEvent::UserDeleted,
            properties: [
                'email' => $user->email,
                'user_id' => $user->id,
            ],
            causer: $user,
            includeRequestContext: false,
        );

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}

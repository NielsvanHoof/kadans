<?php

declare(strict_types=1);

namespace App\Accounts\Actions\Profile;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Soft-delete the signed-in user after writing an audit trail and ending the session.
 */
final readonly class DeleteUserAccountAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
    ) {}

    public function handle(User $user): void
    {
        $this->logAuditActivity->handle(
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

        Session::invalidate();
        Session::regenerateToken();
    }
}

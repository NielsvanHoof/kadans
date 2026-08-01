<?php

declare(strict_types=1);

use App\Tenancy\Enums\Role;
use App\Tenancy\Notifications\WorkspaceMemberInviteNotification;

/**
 * Rendered Markdown mail should pick up the published Qompose theme
 * (ink primary CTA + audit-mark logo in the header).
 */
test('markdown mail theme uses qompose brand color and mark logo', function (): void {
    $mail = new WorkspaceMemberInviteNotification(
        invitationId: 1,
        firmName: 'Acme Accountants',
        role: Role::Adviser,
        acceptUrl: 'https://example.test/invitations/accept',
        expiresAt: now()->addDays(7),
    )->toMail(new stdClass);

    $html = (string) $mail->render();

    expect($html)
        // The Markdown mail pipeline lowercases hex, so match its output.
        ->toContain('#1a1d1f')
        ->toContain('images/brand/mark.png');
});

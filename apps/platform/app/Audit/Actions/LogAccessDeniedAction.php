<?php

declare(strict_types=1);

namespace App\Audit\Actions;

use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Record a refused authorization attempt.
 *
 * Driven from the AuthorizationException render hook in bootstrap/app.php rather
 * than Gate::after: index and show payloads compute `can_*` props with ->can(),
 * several per row (see StaffUploadedDocumentData, StaffAudioRecordingData), so a
 * Gate hook cannot tell a capability probe that renders a disabled button from an
 * attempt that was actually refused. An AuthorizationException only comes from
 * authorize() or a FormRequest denial, which is the event worth recording.
 *
 * A request raises at most one AuthorizationException before it terminates, so no
 * de-duplication is needed here.
 */
final readonly class LogAccessDeniedAction
{
    public function __construct(private LogAuditActivityAction $logAuditActivity) {}

    public function handle(AuthorizationException $exception, Request $request): Activity
    {
        return $this->logAuditActivity->handle(
            AuditEvent::AccessDenied,
            properties: [
                'method' => $request->method(),
                'path' => '/'.mb_ltrim($request->path(), '/'),
                'status' => $exception->status() ?? Response::HTTP_FORBIDDEN,
                'reason' => $exception->getMessage(),
            ],
        );
    }
}

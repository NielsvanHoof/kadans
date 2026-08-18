<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Accounts\Actions\Localization\ResolveApplicationLocaleAction;
use App\Platform\Actions\ResolveLegalDocumentContentAction;
use App\Platform\Enums\LegalDocument;
use App\Shared\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public privacy, cookie, and terms pages (no auth required).
 */
final class LegalDocumentController extends Controller
{
    public function show(
        Request $request,
        string $document,
        ResolveLegalDocumentContentAction $resolveLegalDocumentContent,
        ResolveApplicationLocaleAction $resolveApplicationLocale,
    ): Response {
        $legalDocument = LegalDocument::tryFrom($document);

        throw_unless($legalDocument instanceof LegalDocument, NotFoundHttpException::class);

        $locale = $resolveApplicationLocale->handle($request);

        return Inertia::render(
            'legal/show',
            $resolveLegalDocumentContent->handle($legalDocument, $locale),
        );
    }
}

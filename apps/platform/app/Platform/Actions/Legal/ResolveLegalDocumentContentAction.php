<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Accounts\Enums\Locale;
use App\Platform\Enums\LegalDocument;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function is_string;

/**
 * Load localised legal markdown and render safe HTML for the public legal pages.
 */
final class ResolveLegalDocumentContentAction
{
    /**
     * @return array{
     *     document: string,
     *     title: string,
     *     locale: string,
     *     html: string,
     *     is_draft: bool,
     *     documents: list<array{slug: string, title: string}>
     * }
     */
    public function handle(LegalDocument $document, string $locale): array
    {
        $resolvedLocale = Locale::tryFrom($locale)->value ?? Locale::English->value;
        $markdown = $this->readMarkdown($document, $resolvedLocale);

        // CommonMark via Laravel — HTML input is not trusted from these files,
        // but we still only load from the packaged resources/legal tree.
        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return [
            'document' => $document->value,
            'title' => $document->title(),
            'locale' => $resolvedLocale,
            'html' => $html,
            // Draft banner until counsel clears placeholders / wording.
            'is_draft' => true,
            'documents' => $this->navigation(),
        ];
    }

    private function readMarkdown(LegalDocument $document, string $locale): string
    {
        $path = resource_path('legal/'.$locale.'/'.$document->markdownFile());

        if (! File::isFile($path) && $locale !== Locale::English->value) {
            $path = resource_path('legal/'.Locale::English->value.'/'.$document->markdownFile());
        }

        throw_unless(File::isFile($path), NotFoundHttpException::class, 'Legal document not found.');

        $contents = File::get($path);

        throw_if(! is_string($contents) || $contents === '', RuntimeException::class, 'Legal document is empty: '.$path);

        return $contents;
    }

    /**
     * @return list<array{slug: string, title: string}>
     */
    private function navigation(): array
    {
        return array_map(
            static fn (LegalDocument $document): array => [
                'slug' => $document->value,
                'title' => $document->title(),
            ],
            LegalDocument::cases(),
        );
    }
}

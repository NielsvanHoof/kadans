<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Services\Ocr\Drivers;

use App\DocumentProcessing\Enums\ExtractionFieldSensitivity;

use function json_encode;
use function mb_strtolower;
use function sprintf;
use function str_contains;

/**
 * Realistic Dutch extraction payloads for demos, in the exact shape the OCR
 * pipeline writes to UploadedDocument::extracted_text.
 *
 * Separate from MockOcrExtractor on purpose. The mock's payload is deliberately
 * generic and deterministic because CI asserts against it; changing it to read
 * well on a screen share would break those assertions. This is the other half of
 * that trade-off: content chosen to be legible to an accountant rather than
 * stable for a test. DemoDataSeeder and DemoOcrExtractor share it so seeded and
 * live-uploaded documents look like they came from the same system.
 */
final class DemoExtractionSamples
{
    /**
     * Pick a sample by what the document is called. Falls through to a neutral
     * payload so an unrecognised upload during a demo still renders something
     * plausible rather than an empty extraction panel.
     */
    public static function forTitle(string $title): string
    {
        $normalized = mb_strtolower($title);

        $payload = match (true) {
            str_contains($normalized, 'loonstro') => self::payslip(),
            str_contains($normalized, 'bankafschrift'), str_contains($normalized, 'bankmutatie') => self::bankStatement(),
            str_contains($normalized, 'factuur'), str_contains($normalized, 'facturen') => self::invoice(),
            str_contains($normalized, 'jaaropgave') => self::annualStatement(),
            default => self::generic($title),
        };

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @return array<string, mixed>
     */
    private static function payslip(): array
    {
        return [
            'document_type' => 'loonstrook',
            'summary' => 'Loonstrook augustus 2026 voor een fulltime dienstverband.',
            'fields' => [
                ['label' => 'Werkgever', 'value' => 'Groenewoud Installatietechniek B.V.', 'confidence' => 0.99, 'sensitivity' => null],
                ['label' => 'Werknemer', 'value' => 'P. Groenewoud', 'confidence' => 0.98, 'sensitivity' => null],
                ['label' => 'BSN', 'value' => '182746351', 'confidence' => 0.94, 'sensitivity' => ExtractionFieldSensitivity::Bsn->value],
                ['label' => 'Periode', 'value' => 'augustus 2026', 'confidence' => 0.99, 'sensitivity' => null],
                ['label' => 'Bruto loon', 'value' => '€ 4.250,00', 'confidence' => 0.97, 'sensitivity' => null],
                ['label' => 'Netto loon', 'value' => '€ 3.118,42', 'confidence' => 0.97, 'sensitivity' => null],
            ],
            'tables' => [[
                'title' => 'Inhoudingen',
                'headers' => ['Omschrijving', 'Bedrag'],
                'rows' => [
                    ['Loonheffing', '€ 981,13'],
                    ['Pensioenpremie', '€ 118,45'],
                    ['WW-premie', '€ 32,00'],
                ],
            ]],
            'notes' => ['Vakantiegeld wordt jaarlijks in mei uitbetaald.'],
            'confidence' => 0.97,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function bankStatement(): array
    {
        return [
            'document_type' => 'bankafschrift',
            'summary' => 'Zakelijk bankafschrift met 3 mutaties over de periode.',
            'fields' => [
                ['label' => 'Rekeninghouder', 'value' => 'Hoekstra Transport B.V.', 'confidence' => 0.99, 'sensitivity' => null],
                ['label' => 'IBAN', 'value' => 'NL18 RABO 0322 4567 89', 'confidence' => 0.98, 'sensitivity' => ExtractionFieldSensitivity::Iban->value],
                ['label' => 'Beginsaldo', 'value' => '€ 18.402,55', 'confidence' => 0.96, 'sensitivity' => null],
                ['label' => 'Eindsaldo', 'value' => '€ 21.744,10', 'confidence' => 0.96, 'sensitivity' => null],
            ],
            'tables' => [[
                'title' => 'Mutaties',
                'headers' => ['Datum', 'Omschrijving', 'Bedrag'],
                'rows' => [
                    ['02-07-2026', 'Factuur 2026-0481 Bakkerij Van Dijk', '+ € 4.235,00'],
                    ['11-07-2026', 'Brandstof Shell Zwolle', '- € 412,45'],
                    ['28-07-2026', 'Verzekering Achmea', '- € 481,00'],
                ],
            ]],
            'notes' => [],
            'confidence' => 0.96,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function invoice(): array
    {
        return [
            'document_type' => 'factuur',
            'summary' => 'Inkoopfactuur met 21% BTW, betaaltermijn 30 dagen.',
            'fields' => [
                ['label' => 'Leverancier', 'value' => 'Technische Groothandel Overijssel B.V.', 'confidence' => 0.98, 'sensitivity' => null],
                ['label' => 'Factuurnummer', 'value' => '2026-14872', 'confidence' => 0.99, 'sensitivity' => null],
                ['label' => 'Factuurdatum', 'value' => '14-07-2026', 'confidence' => 0.99, 'sensitivity' => null],
                ['label' => 'BTW-nummer', 'value' => 'NL814461916B01', 'confidence' => 0.95, 'sensitivity' => null],
                ['label' => 'Totaal incl. BTW', 'value' => '€ 2.843,60', 'confidence' => 0.98, 'sensitivity' => null],
            ],
            'tables' => [[
                'title' => 'Regels',
                'headers' => ['Omschrijving', 'Aantal', 'Bedrag'],
                'rows' => [
                    ['Koperen leiding 15mm', '40', '€ 1.120,00'],
                    ['Fittingen assortiment', '12', '€ 480,00'],
                    ['Arbeid montage', '8 uur', '€ 750,00'],
                ],
            ]],
            'notes' => ['BTW 21% over € 2.350,00 = € 493,60.'],
            'confidence' => 0.98,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function annualStatement(): array
    {
        return [
            'document_type' => 'jaaropgave',
            'summary' => 'Jaaropgave 2025 met loon, ingehouden loonheffing en arbeidskorting.',
            'fields' => [
                ['label' => 'Werkgever', 'value' => 'Hoekstra Transport B.V.', 'confidence' => 0.99, 'sensitivity' => null],
                ['label' => 'BSN', 'value' => '284917365', 'confidence' => 0.93, 'sensitivity' => ExtractionFieldSensitivity::Bsn->value],
                ['label' => 'Jaar', 'value' => '2025', 'confidence' => 0.99, 'sensitivity' => null],
                ['label' => 'Loon loonheffing', 'value' => '€ 51.204,00', 'confidence' => 0.97, 'sensitivity' => null],
                ['label' => 'Ingehouden loonheffing', 'value' => '€ 14.883,00', 'confidence' => 0.97, 'sensitivity' => null],
                ['label' => 'Arbeidskorting', 'value' => '€ 4.912,00', 'confidence' => 0.96, 'sensitivity' => null],
            ],
            'tables' => [],
            'notes' => [],
            'confidence' => 0.97,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function generic(string $title): array
    {
        return [
            'document_type' => 'overig',
            'summary' => sprintf('Ingelezen document: %s.', $title),
            'fields' => [
                ['label' => 'Documenttitel', 'value' => $title, 'confidence' => 0.97, 'sensitivity' => null],
                ['label' => 'Aantal pagina\'s', 'value' => '3', 'confidence' => 0.95, 'sensitivity' => null],
            ],
            'tables' => [],
            'notes' => [],
            'confidence' => 0.95,
        ];
    }
}

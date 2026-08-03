<?php

declare(strict_types=1);

namespace App\Questionnaires\Data;

use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Dual-list questionnaire templates index payload.
 */
#[TypeScript]
final class QuestionnaireTemplatesPageData extends Data
{
    /**
     * @param  LengthAwarePaginator<int, array{
     *     id: int,
     *     name: string,
     *     description: string|null,
     *     category: string,
     *     category_label: string,
     *     items_count: int,
     *     is_system: bool
     * }>  $systemTemplates
     * @param  LengthAwarePaginator<int, array{
     *     id: int,
     *     name: string,
     *     description: string|null,
     *     category: string,
     *     category_label: string,
     *     items_count: int,
     *     is_system: bool
     * }>  $firmTemplates
     */
    public function __construct(
        public readonly LengthAwarePaginator $systemTemplates,
        public readonly LengthAwarePaginator $firmTemplates,
    ) {}
}

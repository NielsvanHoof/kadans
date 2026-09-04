<?php

declare(strict_types=1);

namespace App\Questionnaires\Data;

use App\Questionnaires\Enums\QuestionnaireItemType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A single AI-suggested checklist item — not persisted, only proposed for
 * the user to review before adding it through the existing item form.
 *
 * A Spatie Data payload rather than a plain value object because it is
 * serialized straight to the browser by QuestionnaireChecklistDraftController,
 * so the frontend reads a generated type instead of a hand-written one.
 */
#[TypeScript]
final class DraftedChecklistItemData extends Data
{
    public function __construct(
        public readonly string $title,
        public readonly string $instructions,
        public readonly QuestionnaireItemType $type,
    ) {}
}

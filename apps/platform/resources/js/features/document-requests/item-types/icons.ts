import {
    AlignLeft,
    Calendar,
    CheckSquare,
    CircleDot,
    FileUp,
    Hash,
    ListChecks,
    type LucideIcon,
    Type,
} from 'lucide-react';
import type { QuestionnaireItemType } from '@/features/document-requests/item-types/generated';

/**
 * Icon per questionnaire item type, and nothing else.
 *
 * Split out of the staff registry for the same reason `portal-registry.ts`
 * exists: that registry pulls in every builder preview, review body, and answer
 * form, so a surface that only wants to draw a 14px glyph — the dossier waiting
 * panel — would drag the whole builder chunk in with it.
 *
 * `satisfies Record<QuestionnaireItemType, …>` keeps this exhaustive, so a new
 * PHP enum case fails TypeScript here as well as in the registries.
 */
const questionnaireItemTypeIcons = {
    file: FileUp,
    text: Type,
    textarea: AlignLeft,
    date: Calendar,
    number: Hash,
    boolean: CheckSquare,
    choice: CircleDot,
    multi_select: ListChecks,
} satisfies Record<QuestionnaireItemType, LucideIcon>;

export function getQuestionnaireItemTypeIcon(
    type: QuestionnaireItemType,
): LucideIcon {
    return questionnaireItemTypeIcons[type];
}

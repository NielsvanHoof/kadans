import type { ComponentType } from 'react';
import {
    PortalBooleanRequestContent,
    PortalChoiceRequestContent,
    type PortalDocumentRequestTypeProps,
    PortalFileRequestContent,
    PortalMultiSelectRequestContent,
    PortalTextRequestContent,
} from '@/features/document-requests/portal/portal-document-request-type-content';
import type { QuestionnaireItemType } from '@/features/document-requests/types';

/**
 * Portal-only item-type content map.
 * Kept separate from the staff registry so guest portal chunks never import
 * staff review/builder UI. Exhaustiveness still tracks the generated PHP enum.
 */

type PortalDocumentRequestTypeContent =
    ComponentType<PortalDocumentRequestTypeProps>;

const portalDocumentRequestTypeContents = {
    file: PortalFileRequestContent,
    text: PortalTextRequestContent,
    textarea: PortalTextRequestContent,
    date: PortalTextRequestContent,
    number: PortalTextRequestContent,
    boolean: PortalBooleanRequestContent,
    choice: PortalChoiceRequestContent,
    multi_select: PortalMultiSelectRequestContent,
} satisfies Record<QuestionnaireItemType, PortalDocumentRequestTypeContent>;

export function getQuestionnaireItemTypePortalContent(
    type: QuestionnaireItemType,
): PortalDocumentRequestTypeContent {
    return portalDocumentRequestTypeContents[type];
}

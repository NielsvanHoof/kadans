import type { ComponentType } from 'react';
import type { QuestionnaireItemType } from '@/features/document-requests/types';
import {
    PortalBooleanRequestContent,
    PortalChoiceRequestContent,
    type PortalDocumentRequestTypeProps,
    PortalFileRequestContent,
    PortalMultiSelectRequestContent,
    PortalTextRequestContent,
} from '@/features/portal/document-requests/portal-document-request-type-content';

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

import type { ComponentType } from 'react';
import {
    StaffBooleanRequestContent,
    StaffChoiceRequestContent,
    type StaffDocumentRequestTypeProps,
    StaffFileRequestContent,
    StaffMultiSelectRequestContent,
    StaffTextRequestContent,
} from '@/features/document-requests/staff/review/document-request-type-content';
import type { QuestionnaireItemType } from '@/features/document-requests/types';

type StaffDocumentRequestTypeContent =
    ComponentType<StaffDocumentRequestTypeProps>;

const staffDocumentRequestTypeContents = {
    file: StaffFileRequestContent,
    text: StaffTextRequestContent,
    textarea: StaffTextRequestContent,
    date: StaffTextRequestContent,
    number: StaffTextRequestContent,
    boolean: StaffBooleanRequestContent,
    choice: StaffChoiceRequestContent,
    multi_select: StaffMultiSelectRequestContent,
} satisfies Record<QuestionnaireItemType, StaffDocumentRequestTypeContent>;

export function getQuestionnaireItemTypeStaffContent(
    type: QuestionnaireItemType,
): StaffDocumentRequestTypeContent {
    return staffDocumentRequestTypeContents[type];
}

import type { ComponentType } from 'react';
import {
    type StaffAnswerFormProps,
    StaffBooleanAnswerForm,
    StaffTextAnswerForm,
} from '@/features/document-requests/staff/review/staff-answer-forms';
import {
    StaffChoiceAnswerForm,
    StaffMultiSelectAnswerForm,
} from '@/features/document-requests/staff/review/staff-option-answer-forms';
import type { QuestionnaireItemType } from '@/features/document-requests/types';

type StaffAnswerForm = ComponentType<StaffAnswerFormProps>;

/**
 * Maps each field type to the staff form that can answer it.
 * `satisfies Record<QuestionnaireItemType, …>` makes this exhaustive, so adding a
 * field type fails to compile until it has a form here.
 *
 * File requests are answered by uploading a document, so they map to null and the
 * answer panel renders nothing for them.
 */
const staffAnswerForms = {
    file: null,
    text: StaffTextAnswerForm,
    textarea: StaffTextAnswerForm,
    date: StaffTextAnswerForm,
    number: StaffTextAnswerForm,
    boolean: StaffBooleanAnswerForm,
    choice: StaffChoiceAnswerForm,
    multi_select: StaffMultiSelectAnswerForm,
} satisfies Record<QuestionnaireItemType, StaffAnswerForm | null>;

export function getStaffAnswerForm(
    type: QuestionnaireItemType,
): StaffAnswerForm | null {
    return staffAnswerForms[type];
}

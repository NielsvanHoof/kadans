import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import type { QuestionnaireItemType } from '@/features/document-requests/types';

/**
 * Shared native control for answer_text questionnaire types (text, textarea, date, number).
 * Used by both portal and staff review — keep labels/placeholders in each wrapper.
 */
export function AnswerTextControl({
    id,
    type,
    defaultValue,
    placeholder,
    className,
    required = true,
}: {
    id: string;
    type: QuestionnaireItemType;
    defaultValue: string;
    placeholder?: string;
    className?: string;
    required?: boolean;
}) {
    if (type === 'date') {
        return (
            <Input
                id={id}
                name="answer_text"
                type="date"
                required={required}
                defaultValue={defaultValue}
                autoComplete="off"
                className={className ?? 'h-11'}
            />
        );
    }

    if (type === 'number') {
        return (
            <Input
                id={id}
                name="answer_text"
                type="number"
                required={required}
                defaultValue={defaultValue}
                inputMode="decimal"
                autoComplete="off"
                placeholder={placeholder}
                className={className ?? 'h-11'}
            />
        );
    }

    if (type === 'textarea') {
        return (
            <Textarea
                id={id}
                name="answer_text"
                rows={5}
                required={required}
                defaultValue={defaultValue}
                placeholder={placeholder}
                className={className}
            />
        );
    }

    return (
        <Input
            id={id}
            name="answer_text"
            type="text"
            required={required}
            defaultValue={defaultValue}
            autoComplete="off"
            placeholder={placeholder}
            className={className ?? 'h-11'}
        />
    );
}

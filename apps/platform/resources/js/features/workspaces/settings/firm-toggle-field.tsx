import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Checkbox } from '@/components/ui/checkbox';

type FirmToggleFieldProps = {
    name: string;
    label: string;
    description: string;
    isSelected: boolean;
    onChange: (isSelected: boolean) => void;
};

/**
 * The hidden input, not the checkbox, is what submits.
 *
 * An unchecked checkbox sends nothing at all, which would reach the server as a
 * missing field rather than as `false` and fail a `required|boolean` rule. The
 * hidden input always sends one of the two values, so turning a feature off
 * says so explicitly.
 */
export default function FirmToggleField({
    name,
    label,
    description,
    isSelected,
    onChange,
}: FirmToggleFieldProps) {
    return (
        <Field orientation="horizontal" className="items-start gap-3">
            <input type="hidden" name={name} value={isSelected ? '1' : '0'} />

            <Checkbox
                id={name}
                isSelected={isSelected}
                onChange={onChange}
                aria-describedby={`${name}-description`}
            />

            <div className="grid gap-1">
                <FieldLabel htmlFor={name}>{label}</FieldLabel>
                <FieldDescription id={`${name}-description`}>
                    {description}
                </FieldDescription>
            </div>
        </Field>
    );
}

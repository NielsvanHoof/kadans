import InputError, {
    fieldErrorAriaProps,
} from '@/components/primitives/input-error';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';

type InheritedNumberFieldProps = {
    name: string;
    label: string;
    description: string;
    setting: App.Tenancy.Data.Settings.FirmSettingData;
    error?: string;
    /** Shown after the number in the placeholder and the inherited hint. */
    unit: string;
};

/**
 * A number a firm can set for itself, or leave to the standard value.
 *
 * An empty field is not zero — it means the firm has chosen nothing, so the
 * placeholder carries the value that applies instead. The min and max come from
 * the server so the browser and the validator agree about what is allowed.
 */
export default function InheritedNumberField({
    name,
    label,
    description,
    setting,
    error,
    unit,
}: InheritedNumberFieldProps) {
    const { t } = useTranslation();

    return (
        <Field data-invalid={Boolean(error)}>
            <FieldLabel htmlFor={name}>{label}</FieldLabel>

            <Input
                id={name}
                name={name}
                type="number"
                inputMode="numeric"
                min={setting.min}
                max={setting.max ?? undefined}
                defaultValue={setting.override ?? ''}
                placeholder={
                    setting.platform_default === null
                        ? undefined
                        : String(setting.platform_default)
                }
                {...fieldErrorAriaProps(`${name}-error`, error)}
            />

            <FieldDescription>
                {description}{' '}
                {setting.platform_default === null
                    ? t('Leave empty for no default.')
                    : t('Leave empty to use the standard :value :unit.', {
                          value: String(setting.platform_default),
                          unit,
                      })}
            </FieldDescription>

            <InputError id={`${name}-error`} message={error} />
        </Field>
    );
}

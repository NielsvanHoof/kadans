import InputError from '@/components/primitives/input-error';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Field,
    FieldDescription,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { useTranslation } from '@/hooks/use-translation';

type FirmSecuritySectionProps = {
    security: App.Tenancy.Data.TenantSecuritySettingsData;
    error?: string;
};

export default function FirmSecuritySection({
    security,
    error,
}: FirmSecuritySectionProps) {
    const { t } = useTranslation();

    const labels: Record<string, string> = {
        off: t('Not required'),
        by_permission: t('Staff who can download client documents'),
        all: t('Everyone at the firm'),
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Signing in')}</CardTitle>
                <CardDescription>
                    {t(
                        'Ask staff for a second step when they sign in. You can ask more of your team than the standard setting, but not less.',
                    )}
                </CardDescription>
            </CardHeader>

            <CardContent>
                <FieldGroup className="gap-6">
                    <Field data-invalid={Boolean(error)}>
                        <FieldLabel htmlFor="two_factor_enforcement">
                            {t('Who needs a second step')}
                        </FieldLabel>

                        <RadioGroup
                            id="two_factor_enforcement"
                            name="two_factor_enforcement"
                            defaultValue={
                                security.two_factor_enforcement_override ?? ''
                            }
                            aria-describedby="two_factor_enforcement-description"
                        >
                            <Field
                                orientation="horizontal"
                                className="items-center gap-3"
                            >
                                <RadioGroupItem
                                    id="two_factor_enforcement-inherited"
                                    value=""
                                />
                                <FieldLabel htmlFor="two_factor_enforcement-inherited">
                                    {t('Use the standard setting (:value)', {
                                        value: labels[
                                            security
                                                .platform_two_factor_enforcement
                                        ],
                                    })}
                                </FieldLabel>
                            </Field>

                            {security.allowed_enforcement_levels.map(
                                (level) => (
                                    <Field
                                        key={level}
                                        orientation="horizontal"
                                        className="items-center gap-3"
                                    >
                                        <RadioGroupItem
                                            id={`two_factor_enforcement-${level}`}
                                            value={level}
                                        />
                                        <FieldLabel
                                            htmlFor={`two_factor_enforcement-${level}`}
                                        >
                                            {labels[level]}
                                        </FieldLabel>
                                    </Field>
                                ),
                            )}
                        </RadioGroup>

                        <FieldDescription id="two_factor_enforcement-description">
                            {t(
                                'Staff who do not yet have a second step set up are asked to add one before they can continue.',
                            )}
                        </FieldDescription>

                        <InputError message={error} />
                    </Field>
                </FieldGroup>
            </CardContent>
        </Card>
    );
}

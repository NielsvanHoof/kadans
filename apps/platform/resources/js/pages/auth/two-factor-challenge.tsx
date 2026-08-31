import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useEffect, useMemo, useState } from 'react';
import InputError, {
    fieldErrorAriaProps,
} from '@/components/primitives/input-error';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import {
    OTP_MAX_LENGTH,
    OTP_SLOTS,
} from '@/features/security/two-factor/use-two-factor-auth';
import { useTranslation } from '@/hooks/use-translation';
import { store } from '@/routes/two-factor/login';

function ChallengeFormBody({
    showRecoveryInput,
    toggleRecoveryMode,
    authConfigContent,
}: {
    showRecoveryInput: boolean;
    toggleRecoveryMode: (clearErrors: () => void) => void;
    authConfigContent: {
        toggleText: string;
    };
}) {
    const [code, setCode] = useState<string>('');

    return (
        <Form
            {...store.form()}
            resetOnError
            resetOnSuccess={!showRecoveryInput}
        >
            {({ errors, processing, clearErrors, submit }) => (
                <ChallengeFields
                    submit={submit}
                    showRecoveryInput={showRecoveryInput}
                    code={code}
                    setCode={setCode}
                    errors={errors}
                    processing={processing}
                    clearErrors={clearErrors}
                    toggleRecoveryMode={toggleRecoveryMode}
                    authConfigContent={authConfigContent}
                />
            )}
        </Form>
    );
}

function ChallengeFields({
    submit,
    showRecoveryInput,
    code,
    setCode,
    errors,
    processing,
    clearErrors,
    toggleRecoveryMode,
    authConfigContent,
}: {
    // Inertia Form expose submit() on the slot props (ref is FormComponentRef, not HTMLFormElement).
    submit: () => void;
    showRecoveryInput: boolean;
    code: string;
    setCode: (value: string) => void;
    errors: Record<string, string>;
    processing: boolean;
    clearErrors: () => void;
    toggleRecoveryMode: (clearErrors: () => void) => void;
    authConfigContent: { toggleText: string };
}) {
    const { t } = useTranslation();

    useEffect(() => {
        if (
            !showRecoveryInput &&
            !processing &&
            code.length === OTP_MAX_LENGTH
        ) {
            submit();
        }
    }, [code, submit, processing, showRecoveryInput]);

    return (
        <FieldGroup className="gap-4">
            {showRecoveryInput ? (
                <Field data-invalid={Boolean(errors.recovery_code)}>
                    <FieldLabel htmlFor="recovery_code">
                        {t('Recovery code')}
                    </FieldLabel>
                    <Input
                        id="recovery_code"
                        name="recovery_code"
                        type="text"
                        placeholder={t('Enter recovery code')}
                        autoFocus={showRecoveryInput}
                        autoComplete="one-time-code"
                        required
                        {...fieldErrorAriaProps(
                            'recovery_code-error',
                            errors.recovery_code,
                        )}
                    />
                    <InputError
                        id="recovery_code-error"
                        message={errors.recovery_code}
                    />
                </Field>
            ) : (
                <Field
                    className="items-center text-center"
                    data-invalid={Boolean(errors.code)}
                >
                    <FieldLabel htmlFor="two-factor-code" className="sr-only">
                        {t('Authentication code')}
                    </FieldLabel>
                    <div className="flex w-full items-center justify-center">
                        <InputOTP
                            id="two-factor-code"
                            name="code"
                            maxLength={OTP_MAX_LENGTH}
                            value={code}
                            onChange={setCode}
                            disabled={processing}
                            pattern={REGEXP_ONLY_DIGITS}
                            autoFocus
                            aria-label={t('Authentication code')}
                            {...fieldErrorAriaProps('code-error', errors.code)}
                        >
                            <InputOTPGroup>
                                {OTP_SLOTS.map((slot) => (
                                    <InputOTPSlot
                                        key={slot.id}
                                        index={slot.index}
                                    />
                                ))}
                            </InputOTPGroup>
                        </InputOTP>
                    </div>
                    <InputError id="code-error" message={errors.code} />
                </Field>
            )}

            <Field>
                <Button
                    type="submit"
                    className="w-full"
                    isDisabled={processing}
                >
                    {t('Continue')}
                </Button>
            </Field>

            <Field>
                <div className="text-muted-foreground text-center text-sm">
                    <span>{t('or you can')} </span>
                    <button
                        type="button"
                        className="text-foreground decoration-border cursor-pointer underline underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current!"
                        onClick={() => toggleRecoveryMode(clearErrors)}
                    >
                        {authConfigContent.toggleText}
                    </button>
                </div>
            </Field>
        </FieldGroup>
    );
}

export default function TwoFactorChallenge() {
    const { t } = useTranslation();
    const [showRecoveryInput, setShowRecoveryInput] = useState<boolean>(false);

    const authConfigContent = useMemo<{
        title: string;
        description: string;
        toggleText: string;
    }>(() => {
        if (showRecoveryInput) {
            return {
                title: t('Recovery code'),
                description: t(
                    'Please confirm access to your account by entering one of your emergency recovery codes.',
                ),
                toggleText: t('login using an authentication code'),
            };
        }

        return {
            title: t('Authentication code'),
            description: t(
                'Enter the authentication code provided by your authenticator application.',
            ),
            toggleText: t('login using a recovery code'),
        };
    }, [showRecoveryInput, t]);

    setLayoutProps({
        title: authConfigContent.title,
        description: authConfigContent.description,
    });

    const toggleRecoveryMode = (clearErrors: () => void): void => {
        setShowRecoveryInput(!showRecoveryInput);
        clearErrors();
    };

    return (
        <>
            <Head title={t('Two-factor authentication')} />

            <div className="space-y-6">
                <ChallengeFormBody
                    showRecoveryInput={showRecoveryInput}
                    toggleRecoveryMode={toggleRecoveryMode}
                    authConfigContent={authConfigContent}
                />
            </div>
        </>
    );
}

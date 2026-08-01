import { Form } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useEffect, useRef, useState } from 'react';
import InputError, {
    fieldErrorAriaProps,
} from '@/components/primitives/input-error';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup } from '@/components/ui/field';
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
import { confirm } from '@/routes/two-factor';

/**
 * Confirm 2FA with the authenticator app OTP after scanning the QR code.
 */
export default function TwoFactorVerificationStep({
    onClose,
    onBack,
}: {
    onClose: () => void;
    onBack: () => void;
}) {
    const [code, setCode] = useState<string>('');
    const pinInputContainerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        setTimeout(() => {
            pinInputContainerRef.current?.querySelector('input')?.focus();
        }, 0);
    }, []);

    return (
        <Form
            {...confirm.form()}
            onSuccess={() => onClose()}
            resetOnError
            resetOnSuccess
        >
            {({
                processing,
                errors,
                submit,
            }: {
                processing: boolean;
                errors?: { confirmTwoFactorAuthentication?: { code?: string } };
                submit: () => void;
            }) => (
                <VerificationFields
                    submit={submit}
                    pinInputContainerRef={pinInputContainerRef}
                    code={code}
                    setCode={setCode}
                    processing={processing}
                    errors={errors}
                    onBack={onBack}
                />
            )}
        </Form>
    );
}

function VerificationFields({
    submit,
    pinInputContainerRef,
    code,
    setCode,
    processing,
    errors,
    onBack,
}: {
    // Inertia Form expose submit() on the slot props (ref is FormComponentRef, not HTMLFormElement).
    submit: () => void;
    pinInputContainerRef: React.RefObject<HTMLDivElement | null>;
    code: string;
    setCode: (value: string) => void;
    processing: boolean;
    errors?: { confirmTwoFactorAuthentication?: { code?: string } };
    onBack: () => void;
}) {
    const { t } = useTranslation();

    useEffect(() => {
        if (!processing && code.length === OTP_MAX_LENGTH) {
            submit();
        }
    }, [code, submit, processing]);

    return (
        <FieldGroup ref={pinInputContainerRef} className="relative gap-3">
            <Field
                className="items-center py-2"
                data-invalid={Boolean(
                    errors?.confirmTwoFactorAuthentication?.code,
                )}
            >
                <div className="flex justify-center">
                    <InputOTP
                        id="otp"
                        name="code"
                        maxLength={OTP_MAX_LENGTH}
                        value={code}
                        onChange={setCode}
                        disabled={processing}
                        pattern={REGEXP_ONLY_DIGITS}
                        autoFocus
                        aria-label={t('Authentication code')}
                        {...fieldErrorAriaProps(
                            'otp-error',
                            errors?.confirmTwoFactorAuthentication?.code,
                        )}
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
                <InputError
                    id="otp-error"
                    message={errors?.confirmTwoFactorAuthentication?.code}
                    className="text-center"
                />
            </Field>

            <Field orientation="horizontal" className="gap-5">
                <Button
                    type="button"
                    variant="outline"
                    className="flex-1"
                    onClick={onBack}
                    disabled={processing}
                >
                    {t('Back')}
                </Button>
                <Button
                    type="submit"
                    className="flex-1"
                    disabled={processing || code.length < OTP_MAX_LENGTH}
                >
                    {t('Confirm')}
                </Button>
            </Field>
        </FieldGroup>
    );
}

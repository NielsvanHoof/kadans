import { Form } from '@inertiajs/react';
import { useRef } from 'react';
import ProfileController from '@/actions/App/Accounts/Http/Controllers/ProfileController';
import InputError from '@/components/primitives/input-error';
import PasswordInput from '@/components/primitives/password-input';
import SettingsSection from '@/components/primitives/settings-section';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { useTranslation } from '@/hooks/use-translation';

export default function DeleteUser() {
    const passwordInput = useRef<HTMLInputElement>(null);
    const { t } = useTranslation();

    return (
        <SettingsSection
            title={t('Delete account')}
            description={t('Delete your account and all of its resources')}
        >
            <div className="space-y-4 rounded-lg border border-destructive/20 bg-destructive/5 p-4">
                <div className="relative space-y-0.5 text-destructive">
                    <p className="font-medium">{t('Warning')}</p>
                    <p className="text-sm text-destructive/90">
                        {t(
                            'Please proceed with caution, this cannot be undone.',
                        )}
                    </p>
                </div>

                <DialogTrigger>
                    <Button
                        variant="destructive"
                        data-test="delete-user-button"
                    >
                        {t('Delete account')}
                    </Button>
                    <Dialog>
                        <DialogTitle>
                            {t('Are you sure you want to delete your account?')}
                        </DialogTitle>
                        <DialogDescription>
                            {t(
                                'Once your account is deleted, all of its resources and data will also be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.',
                            )}
                        </DialogDescription>

                        <Form
                            {...ProfileController.destroy.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            onError={() => passwordInput.current?.focus()}
                            resetOnSuccess
                        >
                            {({ resetAndClearErrors, processing, errors }) => (
                                <FieldGroup className="gap-6">
                                    <Field
                                        data-invalid={Boolean(errors.password)}
                                    >
                                        <FieldLabel
                                            htmlFor="password"
                                            className="sr-only"
                                        >
                                            {t('Password')}
                                        </FieldLabel>

                                        <PasswordInput
                                            id="password"
                                            name="password"
                                            ref={passwordInput}
                                            placeholder={t('Password')}
                                            autoComplete="current-password"
                                            aria-invalid={Boolean(
                                                errors.password,
                                            )}
                                        />

                                        <InputError message={errors.password} />
                                    </Field>

                                    <Field>
                                        <DialogFooter className="gap-2">
                                            <DialogClose
                                                variant="secondary"
                                                onClick={() =>
                                                    resetAndClearErrors()
                                                }
                                            >
                                                {t('Cancel')}
                                            </DialogClose>

                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                isDisabled={processing}
                                                data-test="confirm-delete-user-button"
                                            >
                                                {t('Delete account')}
                                            </Button>
                                        </DialogFooter>
                                    </Field>
                                </FieldGroup>
                            )}
                        </Form>
                    </Dialog>
                </DialogTrigger>
            </div>
        </SettingsSection>
    );
}

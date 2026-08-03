import { Form } from '@inertiajs/react';
import WorkspaceMemberController from '@/actions/App/Tenancy/Http/Controllers/WorkspaceMemberController';
import InputError from '@/components/primitives/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import type { RoleOption } from '@/features/members/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Invite a colleague by email and assign an initial workspace role.
 * Owner is omitted from the select when the actor cannot assign it.
 */
export default function InviteMemberForm({
    roleOptions,
    canAssignOwner = false,
}: {
    roleOptions: RoleOption[];
    canAssignOwner?: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    // Non-owners must not see the owner option in the invite form.
    const assignableRoleOptions = canAssignOwner
        ? roleOptions
        : roleOptions.filter((option) => option.value !== 'owner');
    // Prefer adviser as the default invite role when it is assignable.
    const defaultRole =
        assignableRoleOptions.find((option) => option.value === 'adviser')
            ?.value ??
        assignableRoleOptions[0]?.value ??
        '';

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Invite member')}</CardTitle>
                <CardDescription>
                    {t(
                        'Send an email invitation to join this workspace with a role.',
                    )}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    {...WorkspaceMemberController.store.form(currentWorkspace)}
                    resetOnSuccess
                >
                    {({ processing, errors }) => (
                        <FieldGroup className="gap-4 sm:grid sm:grid-cols-[1fr_12rem_auto] sm:items-end">
                            <Field data-invalid={Boolean(errors.email)}>
                                <FieldLabel htmlFor="email">
                                    {t('Email address')}
                                </FieldLabel>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoComplete="email"
                                    placeholder={t('email@example.com')}
                                    aria-invalid={Boolean(errors.email)}
                                />
                                <InputError message={errors.email} />
                            </Field>

                            <Field data-invalid={Boolean(errors.role)}>
                                <FieldLabel htmlFor="role">
                                    {t('Role')}
                                </FieldLabel>
                                <Select
                                    isRequired
                                    name="role"
                                    defaultValue={defaultRole}
                                    placeholder={t('Role')}
                                    aria-label={t('Role')}
                                >
                                    <SelectTrigger
                                        id="role"
                                        className="w-full"
                                        aria-invalid={Boolean(errors.role)}
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent className="bg-background">
                                        <SelectGroup>
                                            {assignableRoleOptions.map(
                                                (option) => (
                                                    <SelectItem
                                                        key={option.value}
                                                        id={option.value}
                                                    >
                                                        {option.label}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.role} />
                            </Field>

                            <Field>
                                <Button type="submit" isDisabled={processing}>
                                    {processing && <Spinner />}
                                    {t('Send invite')}
                                </Button>
                            </Field>
                        </FieldGroup>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}

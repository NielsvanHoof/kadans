import { Form } from '@inertiajs/react';
import { useState } from 'react';
import WorkspaceMemberController from '@/actions/App/Tenancy/Http/Controllers/WorkspaceMemberController';
import IndexList from '@/components/data/index-list';
import ConfirmDestroyDialog from '@/components/feedback/confirm-destroy-dialog';
import EmptyState from '@/components/primitives/empty-state';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldLabel } from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { RoleOption, WorkspaceMember } from '@/features/members/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Active and suspended workspace members with role and membership actions.
 *
 * Guards: never show Remove for the current user; hide owner in role options
 * when the actor cannot assign it; block demote/remove for the last active owner.
 */
export default function MembersListCard({
    members,
    roleOptions,
    canAssignOwner = false,
}: {
    members: WorkspaceMember[];
    roleOptions: RoleOption[];
    canAssignOwner?: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    // Count active owners so the UI can protect the last one (backend also enforces this).
    const activeOwnerCount = members.filter(
        (member) => member.status === 'active' && member.role === 'owner',
    ).length;

    // Non-owners must not see the owner option when inviting or changing roles.
    const assignableRoleOptions = canAssignOwner
        ? roleOptions
        : roleOptions.filter((option) => option.value !== 'owner');

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Members')}</CardTitle>
                <CardDescription>
                    {members.length === 1
                        ? t('1 member')
                        : t(':count members', { count: members.length })}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {members.length === 0 ? (
                    <EmptyState title={t('No members yet.')} />
                ) : (
                    <IndexList>
                        {members.map((member) => {
                            const isLastActiveOwner =
                                member.role === 'owner' &&
                                member.status === 'active' &&
                                activeOwnerCount <= 1;
                            // Keep the current role visible even when it is not assignable.
                            const roleMissingFromOptions =
                                Boolean(member.role) &&
                                !assignableRoleOptions.some(
                                    (option) => option.value === member.role,
                                );
                            // Never let a user remove themselves; last owner is also protected.
                            const canRemoveMember =
                                !member.is_current_user && !isLastActiveOwner;

                            return (
                                <div
                                    key={member.id}
                                    className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div className="min-w-0">
                                        <p className="font-medium">
                                            {member.name}
                                            {member.is_current_user
                                                ? ` (${t('you')})`
                                                : ''}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {member.email}
                                            {member.status === 'suspended'
                                                ? ` · ${t('Suspended')}`
                                                : ''}
                                        </p>
                                    </div>

                                    <div className="flex flex-wrap items-center gap-2">
                                        <MemberRoleSelect
                                            member={member}
                                            assignableRoleOptions={
                                                assignableRoleOptions
                                            }
                                            roleMissingFromOptions={
                                                roleMissingFromOptions
                                            }
                                            isLastActiveOwner={
                                                isLastActiveOwner
                                            }
                                        />

                                        {member.status === 'active' &&
                                            !member.is_current_user && (
                                                <ConfirmDestroyDialog
                                                    title={t('Suspend member?')}
                                                    description={t(
                                                        'Suspend :name? They will lose access until reactivated.',
                                                        { name: member.name },
                                                    )}
                                                    confirmLabel={t('Suspend')}
                                                    confirmVariant="default"
                                                    form={WorkspaceMemberController.update.form(
                                                        {
                                                            tenant: currentWorkspace,
                                                            membership:
                                                                member.id,
                                                        },
                                                    )}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                    trigger={
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            isDisabled={
                                                                isLastActiveOwner
                                                            }
                                                        >
                                                            {t('Suspend')}
                                                        </Button>
                                                    }
                                                >
                                                    <input
                                                        type="hidden"
                                                        name="status"
                                                        value="suspended"
                                                    />
                                                </ConfirmDestroyDialog>
                                            )}

                                        {canRemoveMember ? (
                                            <ConfirmDestroyDialog
                                                title={t('Remove member?')}
                                                description={t(
                                                    'Remove :name from this firm. They will lose access immediately.',
                                                    { name: member.name },
                                                )}
                                                confirmLabel={t('Remove')}
                                                form={WorkspaceMemberController.destroy.form(
                                                    {
                                                        tenant: currentWorkspace,
                                                        membership: member.id,
                                                    },
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                trigger={
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                    >
                                                        {t('Remove')}
                                                    </Button>
                                                }
                                            />
                                        ) : null}
                                    </div>
                                </div>
                            );
                        })}
                    </IndexList>
                )}
            </CardContent>
        </Card>
    );
}

/**
 * Role select that confirms before submitting a change.
 */
function MemberRoleSelect({
    member,
    assignableRoleOptions,
    roleMissingFromOptions,
    isLastActiveOwner,
}: {
    member: WorkspaceMember;
    assignableRoleOptions: RoleOption[];
    roleMissingFromOptions: boolean;
    isLastActiveOwner: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const [pendingRole, setPendingRole] = useState<string | null>(null);
    const roleSelectId = `member-role-${member.id}`;

    const pendingRoleLabel =
        assignableRoleOptions.find((option) => option.value === pendingRole)
            ?.label ??
        pendingRole ??
        '';

    return (
        <>
            <Field
                orientation="horizontal"
                className="w-auto"
                data-disabled={
                    member.status === 'suspended' || isLastActiveOwner
                }
            >
                <FieldLabel htmlFor={roleSelectId} className="sr-only">
                    {t('Role for :name', { name: member.name })}
                </FieldLabel>
                <Select
                    selectedKey={member.role ?? undefined}
                    isDisabled={
                        member.status === 'suspended' || isLastActiveOwner
                    }
                    // Ask for confirmation before posting the role change.
                    onSelectionChange={(value) => {
                        if (value === member.role) {
                            return;
                        }

                        setPendingRole(value === null ? null : String(value));
                    }}
                    placeholder={t('Role')}
                    aria-label={t('Role')}
                >
                    <SelectTrigger
                        id={roleSelectId}
                        aria-label={t('Role')}
                        className="min-w-40"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent className="bg-background">
                        <SelectGroup>
                            {assignableRoleOptions.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    id={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                            {roleMissingFromOptions && member.role && (
                                <SelectItem id={member.role}>
                                    {member.role_label ?? member.role}
                                </SelectItem>
                            )}
                        </SelectGroup>
                    </SelectContent>
                </Select>
            </Field>

            <Dialog
                isOpen={pendingRole !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingRole(null);
                    }
                }}
            >
                <DialogHeader>
                    <DialogTitle>{t('Change role?')}</DialogTitle>
                    <DialogDescription>
                        {t(
                            'Change the role of :name to :role? Their permissions will update immediately.',
                            {
                                name: member.name,
                                role: pendingRoleLabel,
                            },
                        )}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...WorkspaceMemberController.update.form({
                        tenant: currentWorkspace,
                        membership: member.id,
                    })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setPendingRole(null)}
                >
                    {({ processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="role"
                                value={pendingRole ?? ''}
                            />
                            <DialogFooter className="gap-2">
                                <DialogClose type="button" variant="secondary">
                                    {t('Cancel')}
                                </DialogClose>
                                <Button
                                    type="submit"
                                    isDisabled={processing || !pendingRole}
                                >
                                    {t('Change role')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </Dialog>
        </>
    );
}

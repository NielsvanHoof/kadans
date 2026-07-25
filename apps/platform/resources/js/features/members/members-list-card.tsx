import { Form } from '@inertiajs/react';
import WorkspaceMemberController from '@/actions/App/Http/Controllers/Tenancy/WorkspaceMemberController';
import ConfirmDestroyDialog from '@/components/confirm-destroy-dialog';
import EmptyState from '@/components/empty-state';
import IndexList from '@/components/index-list';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
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
                            const roleFormId = `member-role-${member.id}`;
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
                                        <Form
                                            id={roleFormId}
                                            {...WorkspaceMemberController.update.form(
                                                {
                                                    tenant: currentWorkspace,
                                                    membership: member.id,
                                                },
                                            )}
                                            options={{ preserveScroll: true }}
                                            className="flex items-center gap-2"
                                        >
                                            {({ processing }) => (
                                                <Select
                                                    name="role"
                                                    defaultValue={
                                                        member.role ?? undefined
                                                    }
                                                    disabled={
                                                        processing ||
                                                        member.status ===
                                                            'suspended' ||
                                                        isLastActiveOwner
                                                    }
                                                    // Submit as soon as a new role is chosen.
                                                    onValueChange={() => {
                                                        queueMicrotask(() => {
                                                            const form =
                                                                document.getElementById(
                                                                    roleFormId,
                                                                );

                                                            if (
                                                                form instanceof
                                                                HTMLFormElement
                                                            ) {
                                                                form.requestSubmit();
                                                            }
                                                        });
                                                    }}
                                                >
                                                    <SelectTrigger
                                                        aria-label={t('Role')}
                                                        className="min-w-40"
                                                    >
                                                        <SelectValue
                                                            placeholder={t(
                                                                'Role',
                                                            )}
                                                        />
                                                    </SelectTrigger>
                                                    <SelectContent className="bg-background">
                                                        {assignableRoleOptions.map(
                                                            (option) => (
                                                                <SelectItem
                                                                    key={
                                                                        option.value
                                                                    }
                                                                    value={
                                                                        option.value
                                                                    }
                                                                >
                                                                    {
                                                                        option.label
                                                                    }
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                        {roleMissingFromOptions &&
                                                            member.role && (
                                                                <SelectItem
                                                                    value={
                                                                        member.role
                                                                    }
                                                                >
                                                                    {member.role_label ??
                                                                        member.role}
                                                                </SelectItem>
                                                            )}
                                                    </SelectContent>
                                                </Select>
                                            )}
                                        </Form>

                                        {member.status === 'active' &&
                                            !member.is_current_user && (
                                                <Form
                                                    {...WorkspaceMemberController.update.form(
                                                        {
                                                            tenant: currentWorkspace,
                                                            membership:
                                                                member.id,
                                                        },
                                                    )}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    {({ processing }) => (
                                                        <>
                                                            <input
                                                                type="hidden"
                                                                name="status"
                                                                value="suspended"
                                                            />
                                                            <Button
                                                                type="submit"
                                                                variant="outline"
                                                                size="sm"
                                                                disabled={
                                                                    processing ||
                                                                    isLastActiveOwner
                                                                }
                                                            >
                                                                {t('Suspend')}
                                                            </Button>
                                                        </>
                                                    )}
                                                </Form>
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

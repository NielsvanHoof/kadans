import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { RoleOption, WorkspaceMember } from '@/features/members/types';

vi.mock('@/hooks/use-translation', () => ({
    useTranslation: () => ({
        t: (key: string, replacements?: Record<string, string | number>) => {
            if (!replacements) {
                return key;
            }

            return Object.entries(replacements).reduce(
                (result, [token, value]) =>
                    result.replace(`:${token}`, String(value)),
                key,
            );
        },
        locale: 'en',
        availableLocales: [],
    }),
}));

vi.mock('@/hooks/use-current-workspace', () => ({
    useCurrentWorkspace: () => ({ slug: 'acme', name: 'Acme' }),
}));

vi.mock('@inertiajs/react', () => ({
    Form: ({ children }: { children: React.ReactNode }) => (
        <form>{children}</form>
    ),
}));

vi.mock(
    '@/actions/App/Tenancy/Http/Controllers/WorkspaceMemberController',
    () => {
        const form = () => ({ action: '/members/1', method: 'post' as const });

        return {
            default: {
                update: Object.assign(() => ({ url: '/members/1' }), { form }),
                destroy: Object.assign(() => ({ url: '/members/1' }), {
                    form,
                }),
            },
        };
    },
);

const { default: MembersListCard } =
    await import('@/features/members/members-list-card');

const member = {
    id: 42,
    name: 'Ada Lovelace',
    email: 'ada@example.com',
    role: 'adviser',
    role_label: 'Adviser',
    status: 'active',
    is_current_user: false,
} as WorkspaceMember;

const roleOptions = [
    { value: 'adviser', label: 'Adviser' },
    { value: 'owner', label: 'Owner' },
] as RoleOption[];

describe('MembersListCard role select labels', () => {
    it('names each role select after the member, not a generic Role label', () => {
        render(
            <MembersListCard members={[member]} roleOptions={roleOptions} />,
        );

        const roleSelect = screen.getByRole('button', {
            name: /Role for Ada Lovelace/,
        });

        expect(roleSelect).toBeInTheDocument();
        expect(roleSelect).not.toHaveAttribute('aria-label');
        expect(document.getElementById('member-role-label-42')).toHaveTextContent(
            'Role for Ada Lovelace',
        );
    });
});

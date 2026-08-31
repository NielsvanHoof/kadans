import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vite-plus/test';
import OAuthProviderButton from '@/features/integrations/oauth-provider-button';

describe('OAuthProviderButton', () => {
    it('renders a Google link with label and brand icon', () => {
        render(
            <OAuthProviderButton
                provider="google"
                href="/oauth/google/redirect"
            >
                Log in with Google
            </OAuthProviderButton>,
        );

        const link = screen.getByRole('link', { name: 'Log in with Google' });

        expect(link).toHaveAttribute('href', '/oauth/google/redirect');
        expect(link.tagName).toBe('A');
        expect(link.querySelector('svg')).toBeInTheDocument();
        expect(
            link.querySelector('svg path[fill="#4285F4"]'),
        ).toBeInTheDocument();
    });

    it('renders a Microsoft link with label and brand icon', () => {
        render(
            <OAuthProviderButton
                provider="microsoft"
                href="/oauth/microsoft/redirect"
            >
                Log in with Microsoft
            </OAuthProviderButton>,
        );

        const link = screen.getByRole('link', {
            name: 'Log in with Microsoft',
        });

        expect(link).toHaveAttribute('href', '/oauth/microsoft/redirect');
        expect(link.tagName).toBe('A');
        expect(link.querySelector('svg')).toBeInTheDocument();
        expect(
            link.querySelector('svg path[fill="#f25022"]'),
        ).toBeInTheDocument();
    });

    it('renders link text without an icon for unknown providers', () => {
        render(
            <OAuthProviderButton
                provider="github"
                href="/oauth/github/redirect"
            >
                Connect
            </OAuthProviderButton>,
        );

        const link = screen.getByRole('link', { name: 'Connect' });

        expect(link).toHaveAttribute('href', '/oauth/github/redirect');
        expect(link.querySelector('svg')).not.toBeInTheDocument();
    });
});

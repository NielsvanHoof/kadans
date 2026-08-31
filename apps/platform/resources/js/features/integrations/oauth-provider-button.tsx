import type { ReactNode } from 'react';
import OAuthProviderIcon from '@/features/integrations/oauth-provider-icon';
import { cn } from '@/lib/utils';

type Props = {
    provider: string;
    href: string;
    children: ReactNode;
    className?: string;
};

const providerStyles: Record<string, string> = {
    google: 'border-[#747775] bg-white text-[#1f1f1f] hover:bg-[#f8faff] focus-visible:border-[#747775] focus-visible:ring-[#747775]/30 dark:bg-white dark:text-[#1f1f1f] dark:hover:bg-[#f8faff]',
    microsoft:
        'border-[#8c8c8c] bg-white text-[#5e5e5e] hover:bg-[#f3f3f3] focus-visible:border-[#8c8c8c] focus-visible:ring-[#8c8c8c]/30 dark:bg-white dark:text-[#5e5e5e] dark:hover:bg-[#f3f3f3]',
};

/**
 * Branded OAuth redirect link for Socialite IdP flows.
 */
export default function OAuthProviderButton({
    provider,
    href,
    children,
    className,
}: Props) {
    return (
        <a
            href={href}
            className={cn(
                'inline-flex h-10 w-full items-center justify-center gap-3 rounded-md border px-4 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none',
                providerStyles[provider] ??
                    'border-border bg-background text-foreground hover:bg-muted',
                className,
            )}
        >
            <OAuthProviderIcon provider={provider} />
            <span>{children}</span>
        </a>
    );
}

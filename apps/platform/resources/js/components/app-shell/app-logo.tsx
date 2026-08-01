import AppLogoIcon from '@/components/app-shell/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-primary text-primary-foreground">
                <AppLogoIcon className="size-5 text-primary-foreground" />
            </div>
            <div className="ml-1 grid flex-1 text-left">
                {/* Wordmark: display face, tracked in tight so it reads as a
                    set logotype rather than a label in the nav. */}
                <span className="mb-0.5 truncate font-display text-[0.9375rem] leading-tight font-bold tracking-[-0.03em]">
                    Qompose
                </span>
            </div>
        </>
    );
}

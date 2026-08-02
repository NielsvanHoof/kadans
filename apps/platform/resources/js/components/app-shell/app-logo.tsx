import AppLogoIcon from '@/components/app-shell/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            {/*
                Coral tile, not ink: this is the one persistent piece of brand
                in the signed-in shell. The mark is a glyph rather than text, so
                the display coral's 3.45:1 against white clears the 3:1 bar for
                non-text contrast.
            */}
            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-brand text-white">
                <AppLogoIcon className="size-5" />
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

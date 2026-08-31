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
            <div className="bg-brand flex aspect-square size-8 items-center justify-center rounded-md text-white">
                <AppLogoIcon className="size-5" />
            </div>
            <div className="ml-1 grid flex-1 text-left">
                {/* Wordmark: Poppins bold, matching qompose.nl logotype weight. */}
                <span className="font-display mb-0.5 truncate text-[0.9375rem] leading-tight font-bold tracking-[-0.02em]">
                    Qompose
                </span>
            </div>
        </>
    );
}

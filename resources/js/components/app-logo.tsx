import AppLogoIcon from './app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <AppLogoIcon className="h-8 w-8 shrink-0" />
            <div className="ml-2 grid flex-1 text-left text-sm">
                {/* The brand is the name only; "ERP" is a plain word beside it, not
                    part of the wordmark, so it keeps the interface font. */}
                <span className="mb-0.5 truncate leading-none font-semibold">
                    <span className="brand-name">Bor de Guna</span> ERP
                </span>
            </div>
        </>
    );
}

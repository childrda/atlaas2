interface Props {
    /** Tailwind / arbitrary classes for size and layout */
    className?: string;
    /** Use on dark backgrounds (e.g. teacher nav): white pad so the mark stays readable */
    onDark?: boolean;
}

export function SiteLogo({ className = '', onDark = false }: Props) {
    const img = (
        <img
            src="/images/atlaas-logo.svg"
            alt="ATLAAS — Augmented Teaching & Learning Assistive AI System"
            className={className}
        />
    );

    if (onDark) {
        return <div className="rounded-md bg-white px-2 py-2 shadow-sm">{img}</div>;
    }

    return img;
}

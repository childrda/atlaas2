interface Props {
    state: 'idle' | 'thinking' | 'done';
    size?: 'sm' | 'md';
}

export function AtlaasAvatar({ state, size = 'md' }: Props) {
    const dim = size === 'sm' ? 28 : 40;

    return (
        <svg width={dim} height={dim} viewBox="0 0 40 40" fill="none" aria-hidden>
            <path
                d="M4 32 Q4 12 20 12 Q36 12 36 32"
                stroke="#1E3A5F"
                strokeWidth="3"
                strokeLinecap="round"
                fill="none"
                style={{
                    opacity: state === 'thinking' ? undefined : 1,
                    animation: state === 'thinking' ? 'pulse 1.2s ease-in-out infinite' : 'none',
                }}
            />
            <path
                d="M10 32 Q10 18 20 18 Q30 18 30 32"
                stroke="#F5A623"
                strokeWidth="2.5"
                strokeLinecap="round"
                fill="none"
            />
            <line x1="2" y1="32" x2="38" y2="32" stroke="#1E3A5F" strokeWidth="2.5" strokeLinecap="round" />
        </svg>
    );
}

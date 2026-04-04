import { useAudioStore } from '@/stores/audio';
import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

interface PageFeatures {
    features?: {
        tts?: boolean;
    };
}

interface Props {
    text: string;
    sessionId: string;
}

type SpeakState = 'idle' | 'loading' | 'playing' | 'error';

function readXsrfTokenFromCookie(): string {
    const row = document.cookie.split('; ').find((r) => r.startsWith('XSRF-TOKEN='));
    if (!row) return '';
    const raw = row.slice('XSRF-TOKEN='.length);
    try {
        return decodeURIComponent(raw);
    } catch {
        return raw;
    }
}

export function SpeakButton({ text, sessionId }: Props) {
    const page = usePage();
    const { features } = page.props as PageFeatures;
    const sharedCsrf = (page.props as { csrf_token?: string }).csrf_token;

    const buttonId = useMemo(() => crypto.randomUUID(), []);
    const currentId = useAudioStore((s) => s.currentId);
    const setCurrentId = useAudioStore((s) => s.setCurrentId);

    const [state, setState] = useState<SpeakState>('idle');
    const audioRef = useRef<HTMLAudioElement | null>(null);
    const objectUrlRef = useRef<string | null>(null);

    const stopPlayback = useCallback(() => {
        audioRef.current?.pause();
        audioRef.current = null;
        if (objectUrlRef.current) {
            URL.revokeObjectURL(objectUrlRef.current);
            objectUrlRef.current = null;
        }
        if (useAudioStore.getState().currentId === buttonId) {
            setCurrentId(null);
        }
    }, [buttonId, setCurrentId]);

    useEffect(() => {
        return () => {
            stopPlayback();
        };
    }, [stopPlayback]);

    useEffect(() => {
        if (currentId !== buttonId && state === 'playing') {
            stopPlayback();
            setState('idle');
        }
    }, [currentId, buttonId, state, stopPlayback]);

    if (!features?.tts) {
        return null;
    }

    const handleClick = async () => {
        if (state === 'playing') {
            stopPlayback();
            setState('idle');
            return;
        }

        if (state === 'loading') {
            return;
        }

        setState('loading');

        try {
            const csrfToken =
                sharedCsrf ??
                document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ??
                '';
            const xsrf = readXsrfTokenFromCookie();

            const response = await fetch(`/learn/sessions/${sessionId}/speak`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'audio/mpeg',
                    'X-CSRF-TOKEN': csrfToken,
                    ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ text }),
            });

            if (!response.ok) {
                setState(response.status === 503 ? 'idle' : 'error');
                return;
            }

            const blob = await response.blob();
            const objectUrl = URL.createObjectURL(blob);
            objectUrlRef.current = objectUrl;

            const audio = new Audio(objectUrl);
            audioRef.current = audio;

            audio.onended = () => {
                setState('idle');
                if (useAudioStore.getState().currentId === buttonId) {
                    setCurrentId(null);
                }
                URL.revokeObjectURL(objectUrl);
                objectUrlRef.current = null;
                audioRef.current = null;
            };

            audio.onerror = () => {
                setState('error');
                URL.revokeObjectURL(objectUrl);
                objectUrlRef.current = null;
                audioRef.current = null;
                if (useAudioStore.getState().currentId === buttonId) {
                    setCurrentId(null);
                }
            };

            setCurrentId(buttonId);
            setState('playing');
            await audio.play();
        } catch {
            setState('error');
            if (useAudioStore.getState().currentId === buttonId) {
                setCurrentId(null);
            }
        }
    };

    const label =
        state === 'idle'
            ? 'Speak'
            : state === 'loading'
              ? 'Loading…'
              : state === 'playing'
                ? 'Stop'
                : 'Unavailable';

    return (
        <button
            type="button"
            onClick={() => void handleClick()}
            disabled={state === 'error' || state === 'loading'}
            aria-label={state === 'playing' ? 'Stop speaking' : 'Speak this message'}
            className={`flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-all duration-150 ${
                state === 'playing'
                    ? 'border-amber-300 bg-amber-50 text-amber-700 hover:bg-amber-100'
                    : state === 'error'
                      ? 'cursor-not-allowed border-gray-200 bg-gray-50 text-gray-300'
                      : 'border-gray-200 bg-white text-gray-500 hover:border-gray-300 hover:text-gray-700'
            }`}
        >
            {state === 'loading' ? <SpinnerIcon /> : state === 'playing' ? <StopIcon /> : <SpeakerIcon />}
            {label}
        </button>
    );
}

function SpeakerIcon() {
    return (
        <svg
            width="13"
            height="13"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden
        >
            <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" />
            <path d="M15.54 8.46a5 5 0 0 1 0 7.07" />
            <path d="M19.07 4.93a10 10 0 0 1 0 14.14" />
        </svg>
    );
}

function StopIcon() {
    return (
        <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden>
            <rect x="6" y="6" width="12" height="12" rx="2" />
        </svg>
    );
}

function SpinnerIcon() {
    return (
        <svg
            width="13"
            height="13"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            className="animate-spin"
            aria-hidden
        >
            <path d="M21 12a9 9 0 1 1-6.219-8.56" />
        </svg>
    );
}

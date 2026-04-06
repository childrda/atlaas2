import ClassroomContent from '@/Components/classroom/ClassroomContent';
import WhiteboardCanvas from '@/Components/classroom/WhiteboardCanvas';
import StudentLayout from '@/Layouts/StudentLayout';
import { buildCsrfFetchHeaders } from '@/lib/laravelCsrf';
import { Link, router, usePage } from '@inertiajs/react';
import { FormEvent, useCallback, useEffect, useMemo, useRef, useState } from 'react';

interface Agent {
    id: string;
    display_name: string;
    avatar_emoji: string;
    color_hex: string;
    role: string;
}

export default function ClassroomPage() {
    const props = usePage().props as {
        session: Record<string, unknown> & { id: string; current_scene_id: string | null };
        lesson: Record<string, unknown> & { title: string; space_id: string | null; scenes?: unknown[] };
        agents: Agent[];
        initialMessages: Record<string, unknown>[];
        csrf_token?: string;
    };
    const { session, lesson, agents, initialMessages, csrf_token: inertiaCsrf } = props;

    const [messages, setMessages] = useState<Record<string, unknown>[]>(initialMessages ?? []);
    const [input, setInput] = useState('');
    const [streaming, setStreaming] = useState(false);
    const [speakingAgentId, setSpeakingAgentId] = useState<string | null>(null);
    const [spotlightId, setSpotlightId] = useState<string | null>(null);
    const [laserTarget, setLaserTarget] = useState<string | null>(null);
    const [currentSceneId, setCurrentSceneId] = useState<string | null>(
        (session.current_scene_id as string | null) ?? null,
    );
    const [lessonComplete, setLessonComplete] = useState(false);
    const [advancing, setAdvancing] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (!streaming) {
            const id = requestAnimationFrame(() => inputRef.current?.focus());
            return () => cancelAnimationFrame(id);
        }
    }, [streaming]);

    const orderedScenes = useMemo(() => {
        const scenes = (lesson.scenes ?? []) as Record<string, unknown>[];
        return [...scenes].sort(
            (a, b) => Number(a.sequence_order ?? 0) - Number(b.sequence_order ?? 0),
        );
    }, [lesson.scenes]);

    const currentScene = useMemo(() => {
        return orderedScenes.find((s) => s.id === currentSceneId) ?? orderedScenes[0] ?? null;
    }, [orderedScenes, currentSceneId]);

    const sceneIndex = useMemo(
        () => orderedScenes.findIndex((s) => s.id === currentScene?.id),
        [orderedScenes, currentScene],
    );
    const hasNextScene = sceneIndex >= 0 && sceneIndex < orderedScenes.length - 1;

    const advanceScene = useCallback(async () => {
        setActionError(null);
        setAdvancing(true);
        try {
            const res = await fetch(`/learn/classroom/${session.id}/advance`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...buildCsrfFetchHeaders(inertiaCsrf),
                },
                body: JSON.stringify({}),
            });
            const raw = await res.text();
            if (!res.ok) {
                setActionError(
                    res.status === 419
                        ? 'Your session expired. Refresh the page and try again.'
                        : raw.replace(/<[^>]+>/g, ' ').slice(0, 180).trim() || `Could not advance (${res.status}).`,
                );
                return;
            }
            let data: { current_scene_id: string | null; lesson_complete: boolean };
            try {
                data = JSON.parse(raw) as typeof data;
            } catch {
                setActionError('Unexpected response from the server. Try refreshing the page.');
                return;
            }
            setCurrentSceneId(data.current_scene_id);
            if (data.lesson_complete) {
                setLessonComplete(true);
            }
        } catch {
            setActionError('Network error — check your connection and try again.');
        } finally {
            setAdvancing(false);
        }
    }, [session.id, inertiaCsrf]);

    const sendMessage = useCallback(async () => {
        const text = input.trim();
        if (!text || streaming) return;

        setInput('');
        setStreaming(true);
        setActionError(null);
        const userMsg = {
            id: `local-${Date.now()}`,
            role: 'student',
            content: text,
        };
        setMessages((m) => [...m, userMsg]);

        let bufferAgent = '';
        const res = await fetch(`/learn/classroom/${session.id}/message`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'text/event-stream',
                ...buildCsrfFetchHeaders(inertiaCsrf),
            },
            body: JSON.stringify({ content: text }),
        });

        if (!res.ok) {
            const errBody = await res.text();
            setStreaming(false);
            setActionError(
                res.status === 419
                    ? 'Your session expired. Refresh the page and try again.'
                    : errBody.replace(/<[^>]+>/g, ' ').slice(0, 200).trim() || `Could not send message (${res.status}).`,
            );
            return;
        }
        if (!res.body) {
            setStreaming(false);
            setActionError('No response stream from the server. Try again or refresh.');
            return;
        }

        const reader = res.body.getReader();
        const dec = new TextDecoder();
        let carry = '';

        const applyAction = (data: Record<string, unknown>) => {
            const name = String(data.actionName ?? '');
            const params = (data.params ?? {}) as Record<string, unknown>;
            if (name === 'spotlight') {
                setSpotlightId(String(params.elementId ?? ''));
                setTimeout(() => setSpotlightId(null), 5000);
            }
            if (name === 'laser') {
                setLaserTarget(String(params.elementId ?? ''));
                setTimeout(() => setLaserTarget(null), 3000);
            }
        };

        while (true) {
            const { value, done } = await reader.read();
            if (done) break;
            carry += dec.decode(value, { stream: true });
            const parts = carry.split('\n\n');
            carry = parts.pop() ?? '';

            for (const block of parts) {
                const line = block.split('\n').find((l) => l.startsWith('data: '));
                if (!line) continue;
                let evt: Record<string, unknown>;
                try {
                    evt = JSON.parse(line.slice(6));
                } catch {
                    continue;
                }
                const t = String(evt.type ?? '');
                const data = (evt.data ?? {}) as Record<string, unknown>;

                if (t === 'cue_user') {
                    const cue = String(data.prompt ?? '').trim();
                    if (cue) {
                        setMessages((m) => [
                            ...m,
                            {
                                id: `cue-${Date.now()}`,
                                role: 'agent',
                                content: cue,
                                agentName: 'Classroom',
                                agentEmoji: '💡',
                                agentColor: '#64748b',
                            },
                        ]);
                    }
                }
                if (t === 'error') {
                    const msg = String(data.message ?? 'Something went wrong.');
                    setMessages((m) => [
                        ...m,
                        {
                            id: `err-${Date.now()}`,
                            role: 'agent',
                            content: msg,
                            agentName: 'Notice',
                            agentEmoji: '⚠️',
                            agentColor: '#b45309',
                        },
                    ]);
                }
                if (t === 'thinking') {
                    setSpeakingAgentId(String(data.agentId ?? ''));
                }
                if (t === 'agent_start') {
                    setSpeakingAgentId(String(data.agentId ?? ''));
                    bufferAgent = '';
                    setMessages((m) => [
                        ...m,
                        {
                            id: `agent-${data.agentId}-${Date.now()}`,
                            role: 'agent',
                            content: '',
                            agentName: data.agentName,
                            agentEmoji: data.agentEmoji,
                            agentColor: data.agentColor,
                        },
                    ]);
                }
                if (t === 'text_delta') {
                    const chunk = String(data.content ?? '');
                    bufferAgent += chunk;
                    setMessages((m) => {
                        const copy = [...m];
                        const last = copy[copy.length - 1];
                        if (last && last.role === 'agent') {
                            copy[copy.length - 1] = { ...last, content: String(last.content ?? '') + chunk };
                        }
                        return copy;
                    });
                }
                if (t === 'action') {
                    applyAction(data);
                }
                if (t === 'agent_end') {
                    setSpeakingAgentId(null);
                }
                if (t === 'done') {
                    setSpeakingAgentId(null);
                }
            }
        }

        setStreaming(false);
    }, [input, session.id, streaming, inertiaCsrf]);

    const dismissActionError = () => setActionError(null);

    const onSubmit = (e: FormEvent) => {
        e.preventDefault();
        void sendMessage();
    };

    const endSession = () => {
        router.post(`/learn/classroom/${session.id}/end`);
    };

    const exitToAtlas = () => {
        router.post(`/learn/classroom/${session.id}/end`);
    };

    return (
        <StudentLayout>
            <div className="flex min-h-[calc(100vh-4rem)] flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    {lesson.space_id ? (
                        <Link href={`/learn/spaces/${lesson.space_id}`} className="text-sm text-[#1E3A5F] hover:underline">
                            ← Space
                        </Link>
                    ) : (
                        <Link href="/learn/spaces" className="text-sm text-[#1E3A5F] hover:underline">
                            ← Spaces
                        </Link>
                    )}
                    <div className="flex gap-2">
                        <button
                            type="button"
                            onClick={exitToAtlas}
                            className="rounded-md border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50"
                        >
                            Just talk to Atlas
                        </button>
                        <button
                            type="button"
                            onClick={endSession}
                            className="rounded-md bg-slate-800 px-3 py-1.5 text-xs text-white hover:bg-slate-900"
                        >
                            End session
                        </button>
                    </div>
                </div>

                <h1 className="text-lg font-medium text-gray-900">{lesson.title}</h1>

                {actionError && (
                    <div
                        role="alert"
                        className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                    >
                        <span>{actionError}</span>
                        <button
                            type="button"
                            onClick={dismissActionError}
                            className="shrink-0 rounded border border-amber-400 px-2 py-0.5 text-xs font-medium hover:bg-amber-100"
                        >
                            Dismiss
                        </button>
                    </div>
                )}

                {lessonComplete && (
                    <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900">
                        You&apos;ve reached the end of this lesson. You can keep chatting below or end the session when
                        you&apos;re done.
                    </div>
                )}

                <div className="grid min-h-0 flex-1 gap-4 lg:grid-cols-[160px_1fr_280px]">
                    <aside className="flex flex-row gap-2 overflow-x-auto lg:flex-col lg:overflow-visible">
                        {(agents as Agent[]).map((a) => (
                            <div
                                key={a.id}
                                className={`flex shrink-0 items-center gap-2 rounded-lg border px-3 py-2 text-sm ${
                                    speakingAgentId === a.id ? 'ring-2 ring-amber-400' : ''
                                }`}
                                style={{ borderColor: a.color_hex }}
                            >
                                <span className="text-lg">{a.avatar_emoji}</span>
                                <div>
                                    <div className="font-medium" style={{ color: a.color_hex }}>
                                        {a.display_name}
                                    </div>
                                    <div className="text-[10px] uppercase text-gray-400">{a.role}</div>
                                </div>
                            </div>
                        ))}
                    </aside>

                    <ClassroomContent
                        scene={currentScene}
                        messages={messages}
                        spotlightId={spotlightId}
                        laserTarget={laserTarget}
                        session={{ id: session.id }}
                        lessonComplete={lessonComplete}
                        advancing={advancing}
                        onAdvanceScene={advanceScene}
                        hasNextScene={hasNextScene}
                    />

                    <aside className="min-h-[200px] lg:min-h-0">
                        <h2 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                            Whiteboard
                        </h2>
                        <WhiteboardCanvas sessionId={session.id} />
                    </aside>
                </div>

                <form onSubmit={onSubmit} className="flex gap-2 border-t pt-4">
                    <input
                        ref={inputRef}
                        className="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm read-only:cursor-wait"
                        placeholder="Type your message..."
                        value={input}
                        onChange={(e) => setInput(e.target.value)}
                        readOnly={streaming}
                        aria-busy={streaming}
                    />
                    <button
                        type="submit"
                        disabled={streaming || !input.trim()}
                        className="rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                        style={{ backgroundColor: '#1E3A5F' }}
                    >
                        {streaming ? '…' : 'Send'}
                    </button>
                </form>
            </div>
        </StudentLayout>
    );
}

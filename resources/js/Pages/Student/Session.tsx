import { AtlaasAvatar } from '@/Components/Atlaas/AtlaasAvatar';
import { ChatBubble } from '@/Components/Atlaas/ChatBubble';
import { SpeakButton } from '@/Components/Atlaas/SpeakButton';
import { ThinkingIndicator } from '@/Components/Atlaas/ThinkingIndicator';
import type { Message, MessageSegment, StudentSession } from '@/types/models';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

interface Props {
    session: StudentSession;
    messages: Message[];
}

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

export default function SessionPage({ session, messages: initialMessages }: Props) {
    const page = usePage();
    const sharedCsrf = (page.props as { csrf_token?: string }).csrf_token;

    const [messages, setMessages] = useState<Message[]>(initialMessages);
    const [inputValue, setInputValue] = useState('');
    const [isStreaming, setIsStreaming] = useState(false);
    const [streamingContent, setStreamingContent] = useState('');
    const [limitReached, setLimitReached] = useState(false);
    const bottomRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages, streamingContent]);

    const pushAssistantMessage = (content: string, segments?: MessageSegment[]) => {
        setMessages((prev) => [
            ...prev,
            {
                id: crypto.randomUUID(),
                role: 'assistant',
                content,
                ...(segments && segments.length > 0 ? { segments } : {}),
                created_at: new Date().toISOString(),
            },
        ]);
    };

    const sendMessage = async () => {
        const content = inputValue.trim();
        if (!content || isStreaming || limitReached) return;

        const userMsg: Message = {
            id: crypto.randomUUID(),
            role: 'user',
            content,
            created_at: new Date().toISOString(),
        };
        setMessages((prev) => [...prev, userMsg]);
        setInputValue('');
        setIsStreaming(true);
        setStreamingContent('');

        try {
            const csrfToken =
                sharedCsrf ??
                document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ??
                '';
            const xsrf = readXsrfTokenFromCookie();

            const response = await fetch(`/learn/sessions/${session.id}/messages`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'text/event-stream',
                    'X-CSRF-TOKEN': csrfToken,
                    ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ content }),
            });

            if (!response.ok || !response.body) {
                const text = await response.text().catch(() => '');
                throw new Error(`HTTP ${response.status}${text ? `: ${text.slice(0, 200)}` : ''}`);
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let accumulated = '';

            let sawTerminalEvent = false;

            const finishStreaming = () => {
                setStreamingContent('');
                setIsStreaming(false);
            };

            const parseSseLine = (rawLine: string) => {
                const line = rawLine.replace(/\r$/, '').trimEnd();
                if (!line.startsWith('data: ')) return;

                let data: { type: string; content?: string; message?: string; segments?: MessageSegment[] };
                try {
                    data = JSON.parse(line.slice(6)) as typeof data;
                } catch {
                    return;
                }

                if (data.type === 'chunk' && typeof data.content === 'string') {
                    accumulated += data.content;
                    setStreamingContent(accumulated);
                }

                if (data.type === 'done') {
                    sawTerminalEvent = true;
                    const segs = Array.isArray(data.segments) ? data.segments : undefined;
                    pushAssistantMessage(accumulated, segs);
                    accumulated = '';
                    finishStreaming();
                }

                if (data.type === 'limit_reached') {
                    sawTerminalEvent = true;
                    setLimitReached(true);
                    finishStreaming();
                }

                if (data.type === 'error') {
                    sawTerminalEvent = true;
                    pushAssistantMessage(data.message ?? 'Something went wrong. Please try again.');
                    accumulated = '';
                    finishStreaming();
                }
            };

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop() ?? '';

                for (const line of lines) {
                    parseSseLine(line);
                }
            }

            if (buffer.length > 0) {
                parseSseLine(buffer);
                buffer = '';
            }

            if (!sawTerminalEvent) {
                if (accumulated.length > 0) {
                    pushAssistantMessage(accumulated);
                }
                finishStreaming();
            }
        } catch {
            setIsStreaming(false);
            setStreamingContent('');
            pushAssistantMessage('Something went wrong. Please try again.');
        }
    };

    const handleKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            void sendMessage();
        }
    };

    const endSession = () => {
        router.post(`/learn/sessions/${session.id}/end`);
    };

    return (
        <div className="flex h-screen flex-col bg-white">
            <header className="flex items-center justify-between border-b border-gray-100 px-6 py-3">
                <div className="flex items-center gap-3">
                    <AtlaasAvatar state={isStreaming ? 'thinking' : 'idle'} />
                    <div>
                        <p className="text-sm font-medium text-gray-900">{session.space.title}</p>
                        <p className="text-xs text-gray-400">Powered by ATLAAS</p>
                    </div>
                </div>
                <button
                    type="button"
                    onClick={endSession}
                    className="rounded-md border border-gray-200 px-4 py-1.5 text-sm text-gray-600 hover:bg-gray-50"
                >
                    I&apos;m done
                </button>
            </header>

            <div className="flex-1 space-y-4 overflow-y-auto px-6 py-4">
                {messages.length === 0 && (
                    <p className="mt-8 text-center text-sm text-gray-400">Say hello to get started!</p>
                )}

                {messages.map((msg) =>
                    msg.role === 'assistant' ? (
                        <div key={msg.id} className="flex flex-col gap-1">
                            <ChatBubble message={msg} />
                            <div className="flex justify-start pl-1">
                                <SpeakButton text={msg.content} sessionId={session.id} />
                            </div>
                        </div>
                    ) : (
                        <ChatBubble key={msg.id} message={msg} />
                    ),
                )}

                {isStreaming && streamingContent && (
                    <ChatBubble
                        message={{
                            id: 'streaming',
                            role: 'assistant',
                            content: streamingContent,
                            created_at: '',
                        }}
                        isStreaming
                    />
                )}

                {isStreaming && !streamingContent && (
                    <div className="flex items-center gap-2">
                        <AtlaasAvatar state="thinking" size="sm" />
                        <ThinkingIndicator />
                    </div>
                )}

                {limitReached && (
                    <p className="py-4 text-center text-sm text-amber-600">
                        You&apos;ve reached the message limit for this session. Click &quot;I&apos;m done&quot; to
                        finish.
                    </p>
                )}

                <div ref={bottomRef} />
            </div>

            <div className="border-t border-gray-100 px-6 py-4">
                <div className="flex gap-3">
                    <textarea
                        value={inputValue}
                        onChange={(e) => setInputValue(e.target.value)}
                        onKeyDown={handleKeyDown}
                        placeholder="Ask ATLAAS something..."
                        rows={1}
                        disabled={isStreaming || limitReached}
                        className="flex-1 resize-none rounded-xl border border-gray-200 px-4 py-3 text-sm focus:border-amber-400 focus:outline-none disabled:opacity-50"
                    />
                    <button
                        type="button"
                        onClick={() => void sendMessage()}
                        disabled={isStreaming || !inputValue.trim() || limitReached}
                        className="rounded-xl bg-amber-500 px-5 py-3 text-sm font-medium text-white hover:bg-amber-600 disabled:opacity-50"
                    >
                        Send
                    </button>
                </div>
                <p className="mt-2 text-center text-xs text-gray-400">
                    Press Enter to send · Shift+Enter for new line
                </p>
            </div>
        </div>
    );
}

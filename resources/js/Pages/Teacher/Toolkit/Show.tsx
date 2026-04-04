import { DynamicToolForm, type ToolInputs } from '@/Components/Toolkit/DynamicToolForm';
import { StreamingOutput } from '@/Components/Toolkit/StreamingOutput';
import { ToolkitToolIcon } from '@/Components/Toolkit/ToolkitToolIcon';
import TeacherLayout from '@/Layouts/TeacherLayout';
import type { TeacherTool } from '@/types/models';
import { Link, usePage } from '@inertiajs/react';
import { useCallback, useRef, useState } from 'react';

function readXsrfTokenFromCookie(): string {
    const row = document.cookie.split('; ').find((r) => r.startsWith('XSRF-TOKEN='));
    if (!row) {
        return '';
    }
    const raw = row.slice('XSRF-TOKEN='.length);
    try {
        return decodeURIComponent(raw);
    } catch {
        return raw;
    }
}

export default function ToolkitShow() {
    const page = usePage();
    const { tool } = page.props as { tool: TeacherTool };
    const sharedCsrf = (page.props as { csrf_token?: string }).csrf_token;

    const [output, setOutput] = useState('');
    const [isStreaming, setIsStreaming] = useState(false);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const lastInputsRef = useRef<ToolInputs | null>(null);

    const runWithInputs = useCallback(
        async (inputs: ToolInputs) => {
            lastInputsRef.current = inputs;
            setFieldErrors({});
            setOutput('');
            setIsStreaming(true);

            const csrfToken =
                sharedCsrf ??
                document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ??
                '';
            const xsrf = readXsrfTokenFromCookie();

            try {
                const response = await fetch(`/teach/toolkit/${tool.slug}/run`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'text/event-stream',
                        'X-CSRF-TOKEN': csrfToken,
                        ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ inputs }),
                });

                if (response.status === 422) {
                    const data = (await response.json()) as { errors?: Record<string, string[]> };
                    const next: Record<string, string> = {};
                    if (data.errors) {
                        for (const [k, v] of Object.entries(data.errors)) {
                            if (v[0]) {
                                next[k] = v[0];
                            }
                        }
                    }
                    setFieldErrors(next);
                    setIsStreaming(false);

                    return;
                }

                if (!response.ok || !response.body) {
                    const text = await response.text().catch(() => '');
                    throw new Error(`HTTP ${response.status}${text ? `: ${text.slice(0, 200)}` : ''}`);
                }

                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                let accumulated = '';

                const parseSseLine = (rawLine: string) => {
                    const line = rawLine.replace(/\r$/, '').trimEnd();
                    if (!line.startsWith('data: ')) {
                        return;
                    }

                    let data: { type: string; content?: string; message?: string };
                    try {
                        data = JSON.parse(line.slice(6)) as typeof data;
                    } catch {
                        return;
                    }

                    if (data.type === 'chunk' && typeof data.content === 'string') {
                        accumulated += data.content;
                        setOutput(accumulated);
                    }

                    if (data.type === 'done') {
                        setIsStreaming(false);
                    }

                    if (data.type === 'error') {
                        accumulated = data.message ?? 'Something went wrong.';
                        setOutput(accumulated);
                        setIsStreaming(false);
                    }
                };

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) {
                        break;
                    }

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop() ?? '';

                    for (const line of lines) {
                        parseSseLine(line);
                    }
                }

                if (buffer.length > 0) {
                    parseSseLine(buffer);
                }

                setIsStreaming(false);
            } catch {
                setOutput('Something went wrong. Please try again.');
                setIsStreaming(false);
            }
        },
        [sharedCsrf, tool.slug],
    );

    return (
        <TeacherLayout>
            <div className="px-6 py-8">
                <Link href="/teach/toolkit" className="text-sm text-[#1E3A5F] hover:underline">
                    ← Toolkit
                </Link>

                <div className="mt-4 flex items-start gap-3">
                    <div
                        className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl"
                        style={{ backgroundColor: '#EEF2F8', color: '#1E3A5F' }}
                    >
                        <ToolkitToolIcon name={tool.icon} className="h-6 w-6" />
                    </div>
                    <div>
                        <h1 className="text-xl font-medium text-gray-900">{tool.name}</h1>
                        <p className="mt-1 text-sm text-gray-500">{tool.description}</p>
                    </div>
                </div>

                <div className="mt-8 grid gap-8 lg:grid-cols-2">
                    <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                        <h2 className="text-sm font-semibold text-gray-900">Inputs</h2>
                        <div className="mt-4">
                            <DynamicToolForm
                                tool={tool}
                                disabled={isStreaming}
                                fieldErrors={fieldErrors}
                                onSubmit={(inputs) => void runWithInputs(inputs)}
                            />
                        </div>
                    </div>

                    <StreamingOutput
                        content={output}
                        isStreaming={isStreaming}
                        onCopy={() => void navigator.clipboard.writeText(output)}
                        onRegenerate={() => {
                            if (lastInputsRef.current) {
                                void runWithInputs(lastInputsRef.current);
                            }
                        }}
                        canRegenerate={!isStreaming && lastInputsRef.current !== null}
                    />
                </div>
            </div>
        </TeacherLayout>
    );
}

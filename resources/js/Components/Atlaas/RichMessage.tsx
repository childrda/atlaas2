import type { MessageSegment } from '@/types/models';
import { useState } from 'react';

function TextBlock({ content }: { content: string }) {
    return <p className="whitespace-pre-wrap text-gray-900">{content}</p>;
}

function ImageBlock({ segment }: { segment: Extract<MessageSegment, { type: 'image' }> }) {
    const r = segment.resolved;
    if (!r?.url) {
        return (
            <p className="rounded-lg border border-dashed border-gray-200 bg-gray-50/80 px-3 py-2 text-xs text-gray-500">
                Image unavailable{segment.keyword ? ` for "${segment.keyword}".` : '.'}
            </p>
        );
    }

    return (
        <figure className="my-2 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <img
                src={r.url}
                alt={r.alt ?? segment.keyword}
                className="max-h-64 w-full object-cover"
                loading="lazy"
            />
            {(r.credit || r.credit_url) && (
                <figcaption className="border-t border-gray-100 px-3 py-2 text-xs text-gray-500">
                    {r.credit_url ? (
                        <a href={r.credit_url} target="_blank" rel="noopener noreferrer" className="underline">
                            {r.credit ?? 'Source'}
                        </a>
                    ) : (
                        r.credit
                    )}
                    {r.license ? <span className="text-gray-400"> · {r.license}</span> : null}
                </figcaption>
            )}
        </figure>
    );
}

function DiagramBlock({ segment }: { segment: Extract<MessageSegment, { type: 'diagram' }> }) {
    if (!segment.svg) {
        return null;
    }

    return (
        <div
            className="my-2 max-w-full overflow-x-auto rounded-xl border border-gray-200 bg-white p-3 [&_svg]:max-h-80 [&_svg]:w-auto"
            // SVG is generated server-side with escaped text nodes.
            dangerouslySetInnerHTML={{ __html: segment.svg }}
        />
    );
}

function FunFactBlock({ content }: { content: string }) {
    return (
        <div className="my-2 rounded-xl border border-amber-200/80 bg-amber-50 px-4 py-3 text-sm text-amber-950">
            <p className="text-xs font-semibold uppercase tracking-wide text-amber-700">Fun fact</p>
            <p className="mt-1 leading-relaxed">{content}</p>
        </div>
    );
}

function QuizBlock({
    question,
    options,
    answer,
}: {
    question: string;
    options: string[];
    answer: string;
}) {
    const [picked, setPicked] = useState<string | null>(null);

    return (
        <div className="my-2 rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm">
            <p className="text-sm font-medium text-gray-900">{question}</p>
            <div className="mt-3 flex flex-col gap-2">
                {options.map((opt) => {
                    const isSelected = picked === opt;
                    const showResult = picked !== null;
                    const isCorrect = opt === answer;
                    let btnClass =
                        'w-full rounded-lg border px-3 py-2 text-left text-sm transition-colors disabled:cursor-default';
                    if (!showResult) {
                        btnClass += ' border-gray-200 bg-gray-50 hover:border-amber-300 hover:bg-amber-50/50';
                    } else if (isCorrect) {
                        btnClass += ' border-emerald-400 bg-emerald-50 text-emerald-900';
                    } else if (isSelected && !isCorrect) {
                        btnClass += ' border-rose-300 bg-rose-50 text-rose-900';
                    } else {
                        btnClass += ' border-gray-100 bg-gray-50/50 text-gray-400';
                    }

                    return (
                        <button
                            key={opt}
                            type="button"
                            disabled={showResult}
                            onClick={() => setPicked(opt)}
                            className={btnClass}
                        >
                            {opt}
                        </button>
                    );
                })}
            </div>
            {picked !== null && (
                <p className="mt-2 text-xs text-gray-600">
                    {picked === answer ? 'Nice work — that’s right!' : `The answer is: ${answer}`}
                </p>
            )}
        </div>
    );
}

interface Props {
    segments: MessageSegment[];
}

export function RichMessage({ segments }: Props) {
    return (
        <div className="space-y-2">
            {segments.map((seg, i) => {
                switch (seg.type) {
                    case 'text':
                        return <TextBlock key={`t-${i}`} content={seg.content} />;
                    case 'image':
                        return <ImageBlock key={`i-${i}`} segment={seg} />;
                    case 'diagram':
                        return <DiagramBlock key={`d-${i}`} segment={seg} />;
                    case 'fun_fact':
                        return <FunFactBlock key={`f-${i}`} content={seg.content} />;
                    case 'quiz':
                        return (
                            <QuizBlock
                                key={`q-${i}`}
                                question={seg.question}
                                options={seg.options}
                                answer={seg.answer}
                            />
                        );
                    default:
                        return null;
                }
            })}
        </div>
    );
}

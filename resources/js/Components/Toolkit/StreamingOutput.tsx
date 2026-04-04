export function StreamingOutput({
    content,
    isStreaming,
    onCopy,
    onRegenerate,
    canRegenerate,
}: {
    content: string;
    isStreaming: boolean;
    onCopy: () => void;
    onRegenerate: () => void;
    canRegenerate: boolean;
}) {
    return (
        <div className="flex h-full min-h-[320px] flex-col rounded-xl border border-gray-200 bg-white shadow-sm">
            <div className="border-b border-gray-100 px-4 py-2">
                <h2 className="text-sm font-semibold text-gray-900">Output</h2>
            </div>
            <div className="flex-1 overflow-y-auto p-4">
                {content || isStreaming ? (
                    <pre className="whitespace-pre-wrap break-words font-sans text-sm leading-relaxed text-gray-800">
                        {content}
                        {isStreaming && <span className="ml-0.5 inline-block h-4 w-0.5 animate-pulse bg-[#1E3A5F]" />}
                    </pre>
                ) : (
                    <p className="text-sm text-gray-400">Run the tool to see AI output here.</p>
                )}
            </div>
            {content && !isStreaming ? (
                <div className="flex gap-2 border-t border-gray-100 p-3">
                    <button
                        type="button"
                        onClick={onCopy}
                        disabled={!content}
                        className="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                    >
                        Copy
                    </button>
                    <button
                        type="button"
                        onClick={onRegenerate}
                        disabled={!canRegenerate}
                        className="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                    >
                        Regenerate
                    </button>
                </div>
            ) : null}
        </div>
    );
}

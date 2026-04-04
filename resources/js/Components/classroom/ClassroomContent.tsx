import QuizWidget from './QuizWidget';
import SlideRenderer from './SlideRenderer';

interface ClassroomContentProps {
    scene: Record<string, unknown> | null;
    messages: Record<string, unknown>[];
    spotlightId: string | null;
    laserTarget: string | null;
    session: { id: string };
    lessonComplete: boolean;
    advancing: boolean;
    onAdvanceScene: () => void | Promise<void>;
    hasNextScene: boolean;
}

export default function ClassroomContent({
    scene,
    messages,
    spotlightId,
    laserTarget,
    session,
    lessonComplete,
    advancing,
    onAdvanceScene,
    hasNextScene,
}: ClassroomContentProps) {
    const sceneType = scene?.scene_type as string | undefined;

    const showAdvance =
        !lessonComplete && scene && ['slide', 'interactive', 'discussion'].includes(sceneType ?? '');

    const advanceLabel = hasNextScene ? 'Next section' : 'Finish lesson';

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div className="flex-shrink-0 border-b bg-white">
                {sceneType === 'slide' && (
                    <div className="p-4">
                        <SlideRenderer
                            slide={(scene?.content as Record<string, unknown>) ?? { elements: [] }}
                            spotlightId={spotlightId}
                            laserTarget={laserTarget}
                        />
                        {showAdvance && (
                            <div className="mt-4 flex justify-end">
                                <button
                                    type="button"
                                    disabled={advancing}
                                    onClick={() => void onAdvanceScene()}
                                    className="rounded-lg bg-[#1E3A5F] px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                                >
                                    {advancing ? '…' : advanceLabel}
                                </button>
                            </div>
                        )}
                    </div>
                )}
                {sceneType === 'quiz' && (
                    <div className="max-h-[32rem] min-h-64 overflow-y-auto">
                        <QuizWidget
                            key={String(scene?.id ?? '')}
                            questions={
                                ((scene?.content as Record<string, unknown>)?.questions as Record<string, unknown>[]) ??
                                []
                            }
                            sceneId={String(scene?.id ?? '')}
                            sessionId={session.id}
                            onContinue={lessonComplete ? undefined : () => onAdvanceScene()}
                        />
                    </div>
                )}
                {sceneType === 'interactive' && (
                    <div className="flex min-h-64 flex-col">
                        <iframe
                            srcDoc={String((scene?.content as Record<string, unknown>)?.html ?? '')}
                            className="min-h-48 w-full flex-1 border-0"
                            sandbox="allow-scripts"
                            title="Interactive simulation"
                        />
                        {showAdvance && (
                            <div className="flex justify-end border-t bg-gray-50 px-4 py-2">
                                <button
                                    type="button"
                                    disabled={advancing}
                                    onClick={() => void onAdvanceScene()}
                                    className="rounded-lg bg-[#1E3A5F] px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                                >
                                    {advancing ? '…' : advanceLabel}
                                </button>
                            </div>
                        )}
                    </div>
                )}
                {sceneType === 'discussion' && (
                    <div className="border-b bg-amber-50 p-4">
                        <p className="text-sm font-medium text-amber-800">
                            💬 {String((scene?.content as Record<string, unknown>)?.topic ?? 'Discussion')}
                        </p>
                        {(scene?.content as Record<string, unknown>)?.prompt && (
                            <p className="mt-1 text-xs text-amber-600">
                                {String((scene?.content as Record<string, unknown>).prompt)}
                            </p>
                        )}
                        {showAdvance && (
                            <div className="mt-4 flex justify-end">
                                <button
                                    type="button"
                                    disabled={advancing}
                                    onClick={() => void onAdvanceScene()}
                                    className="rounded-lg bg-amber-800 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                                >
                                    {advancing ? '…' : advanceLabel}
                                </button>
                            </div>
                        )}
                    </div>
                )}
            </div>

            <div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
                {messages.map((msg) =>
                    msg.role === 'agent' ? (
                        <AgentChatBubble key={String(msg.id)} message={msg} />
                    ) : (
                        <StudentChatBubble key={String(msg.id)} message={msg} />
                    )
                )}
            </div>
        </div>
    );
}

function StudentChatBubble({ message }: { message: Record<string, unknown> }) {
    return (
        <div className="flex justify-end">
            <div className="max-w-[75%] rounded-2xl rounded-tr-sm border border-amber-200 bg-amber-50 px-4 py-2">
                <p className="text-sm text-amber-900">{String(message.content ?? '')}</p>
            </div>
        </div>
    );
}

function AgentChatBubble({ message }: { message: Record<string, unknown> }) {
    const color = String(message.agentColor ?? '#1E3A5F');
    return (
        <div className="flex items-start gap-2">
            <div
                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm"
                style={{
                    backgroundColor: `${color}20`,
                    border: `2px solid ${color}`,
                }}
            >
                {String(message.agentEmoji ?? '🤖')}
            </div>
            <div className="min-w-0 flex-1">
                <p className="mb-1 text-xs font-medium" style={{ color }}>
                    {String(message.agentName ?? 'Agent')}
                </p>
                <div className="inline-block max-w-full rounded-2xl rounded-tl-sm border bg-white px-4 py-2">
                    <p className="whitespace-pre-wrap text-sm text-gray-800">{String(message.content ?? '')}</p>
                </div>
            </div>
        </div>
    );
}

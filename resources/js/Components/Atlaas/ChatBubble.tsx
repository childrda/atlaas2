import { RichMessage } from '@/Components/Atlaas/RichMessage';
import type { Message } from '@/types/models';

interface Props {
    message: Message;
    isStreaming?: boolean;
}

export function ChatBubble({ message, isStreaming = false }: Props) {
    const isStudent = message.role === 'user';
    const isTeacher = message.role === 'teacher_inject';

    if (isTeacher) {
        return (
            <div className="mx-auto max-w-md rounded-lg border border-blue-100 bg-blue-50 px-4 py-2 text-center text-sm text-blue-700">
                <span className="font-medium">Your teacher says:</span> {message.content}
            </div>
        );
    }

    const assistantRich =
        message.role === 'assistant' &&
        !isStreaming &&
        message.segments &&
        message.segments.length > 0;

    return (
        <div className={`flex ${isStudent ? 'justify-end' : 'justify-start'}`}>
            <div
                className={`max-w-lg rounded-2xl px-4 py-3 text-sm leading-relaxed ${
                    isStudent
                        ? 'rounded-br-sm bg-amber-100 text-gray-900'
                        : 'rounded-bl-sm bg-gray-100 text-gray-900'
                }`}
            >
                {assistantRich ? (
                    <RichMessage segments={message.segments!} />
                ) : (
                    <>
                        {message.content}
                        {isStreaming && (
                            <span className="ml-0.5 inline-block h-3.5 w-0.5 animate-pulse bg-gray-500" />
                        )}
                    </>
                )}
            </div>
        </div>
    );
}

import { useState } from 'react';

interface QuizQuestion {
    id: string;
    type: 'single' | 'multiple' | 'short_answer';
    question: string;
    options?: { label: string; value: string }[];
    answer?: string[];
    analysis?: string;
    points?: number;
}

interface QuizWidgetProps {
    questions: QuizQuestion[];
    sceneId: string;
    sessionId: string;
    onComplete?: (results: QuizResult[]) => void;
    /** Called when the student continues after viewing results (e.g. advance to next scene). */
    onContinue?: () => void | Promise<void>;
}

interface QuizResult {
    questionIndex: number;
    isCorrect: boolean;
    score: number;
    maxScore: number;
    feedback: string;
    analysis?: string;
}

type Phase = 'intro' | 'answering' | 'grading' | 'results';

export default function QuizWidget({ questions, sceneId, sessionId, onComplete, onContinue }: QuizWidgetProps) {
    const [phase, setPhase] = useState<Phase>('intro');
    const [currentIdx, setCurrentIdx] = useState(0);
    const [answers, setAnswers] = useState<Record<number, string | string[]>>({});
    const [results, setResults] = useState<QuizResult[]>([]);
    const [grading, setGrading] = useState(false);

    const question = questions[currentIdx];
    const totalPts = questions.reduce((s, q) => s + (q.points ?? 10), 0);

    const setAnswer = (value: string | string[]) => {
        setAnswers((prev) => ({ ...prev, [currentIdx]: value }));
    };

    const toggleMulti = (val: string) => {
        const current = (answers[currentIdx] as string[]) ?? [];
        setAnswer(current.includes(val) ? current.filter((v) => v !== val) : [...current, val]);
    };

    const submitQuestion = async () => {
        setGrading(true);
        const answer = answers[currentIdx] ?? (question.type === 'multiple' ? [] : '');

        try {
            const res = await fetch(`/learn/classroom/${sessionId}/quiz/${sceneId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ question_index: currentIdx, answer }),
            });
            const data = await res.json();
            const result: QuizResult = {
                questionIndex: currentIdx,
                isCorrect: data.is_correct,
                score: data.score,
                maxScore: data.max_score,
                feedback: data.feedback,
                analysis: data.analysis,
            };
            const newResults = [...results, result];
            setResults(newResults);

            if (currentIdx < questions.length - 1) {
                setTimeout(() => {
                    setCurrentIdx((i) => i + 1);
                    setGrading(false);
                }, 1800);
            } else {
                setGrading(false);
                setPhase('results');
                onComplete?.(newResults);
            }
        } catch {
            setGrading(false);
        }
    };

    if (phase === 'intro') {
        return (
            <div className="flex h-full flex-col items-center justify-center gap-6 p-8">
                <div className="text-5xl">📝</div>
                <h2 className="text-xl font-semibold text-gray-800">Knowledge Check</h2>
                <p className="text-center text-sm text-gray-500">
                    {questions.length} questions · {totalPts} points total
                </p>
                <button
                    type="button"
                    onClick={() => setPhase('answering')}
                    className="rounded-lg bg-blue-600 px-8 py-3 font-medium text-white transition-colors hover:bg-blue-700"
                >
                    Start Quiz
                </button>
            </div>
        );
    }

    if (phase === 'results') {
        const earned = results.reduce((s, r) => s + r.score, 0);
        const pct = Math.round((earned / totalPts) * 100);

        return (
            <div className="h-full space-y-4 overflow-y-auto p-6">
                <div className="py-4 text-center">
                    <div className="text-4xl font-bold text-blue-600">{pct}%</div>
                    <div className="mt-1 text-sm text-gray-500">
                        {earned} / {totalPts} points
                    </div>
                </div>
                {results.map((r, i) => (
                    <div
                        key={i}
                        className={`rounded-lg border p-4 ${r.isCorrect ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50'}`}
                    >
                        <div className="flex items-start gap-2">
                            <span className="text-lg">{r.isCorrect ? '✅' : '❌'}</span>
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-medium text-gray-800">{questions[i]?.question}</p>
                                {r.feedback && <p className="mt-1 text-xs text-gray-600">{r.feedback}</p>}
                                {r.analysis && <p className="mt-1 text-xs italic text-blue-700">{r.analysis}</p>}
                                <p className="mt-1 text-xs text-gray-400">
                                    {r.score} / {r.maxScore} pts
                                </p>
                            </div>
                        </div>
                    </div>
                ))}
                {onContinue && (
                    <button
                        type="button"
                        onClick={() => void onContinue()}
                        className="mt-4 w-full rounded-lg bg-[#1E3A5F] py-3 text-sm font-medium text-white hover:bg-[#162d4a]"
                    >
                        Continue
                    </button>
                )}
            </div>
        );
    }

    const currentResult = results.find((r) => r.questionIndex === currentIdx);
    const hasAnswer =
        answers[currentIdx] !== undefined &&
        (Array.isArray(answers[currentIdx])
            ? (answers[currentIdx] as string[]).length > 0
            : String(answers[currentIdx]).trim() !== '');

    return (
        <div className="flex h-full flex-col space-y-4 p-6">
            <div className="flex items-center gap-3">
                <div className="h-1.5 flex-1 rounded-full bg-gray-200">
                    <div
                        className="h-1.5 rounded-full bg-blue-500 transition-all"
                        style={{ width: `${((currentIdx + 1) / questions.length) * 100}%` }}
                    />
                </div>
                <span className="whitespace-nowrap text-xs text-gray-500">
                    {currentIdx + 1} / {questions.length}
                </span>
            </div>

            <div className="min-h-0 flex-1 space-y-4">
                <div className="flex items-start gap-2">
                    <span className="mt-0.5 whitespace-nowrap rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">
                        {question.points ?? 10} pts
                    </span>
                    <p className="font-medium text-gray-800">{question.question}</p>
                </div>

                {question.type === 'single' && question.options && (
                    <div className="space-y-2">
                        {question.options.map((opt) => {
                            const selected = answers[currentIdx] === opt.value;
                            const isRight = currentResult && opt.value === question.answer?.[0];
                            const isWrong = currentResult && selected && !currentResult.isCorrect;

                            return (
                                <button
                                    key={opt.value}
                                    type="button"
                                    onClick={() => !currentResult && setAnswer(opt.value)}
                                    className={`flex w-full items-center gap-3 rounded-lg border p-3 text-left text-sm transition-colors ${
                                        isRight
                                            ? 'border-green-400 bg-green-50'
                                            : isWrong
                                              ? 'border-red-400 bg-red-50'
                                              : selected
                                                ? 'border-blue-400 bg-blue-50'
                                                : 'border-gray-200 hover:border-gray-300'
                                    }`}
                                >
                                    <span
                                        className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs font-medium ${
                                            selected ? 'border-blue-500 bg-blue-500 text-white' : 'border-gray-300'
                                        }`}
                                    >
                                        {opt.value}
                                    </span>
                                    {opt.label}
                                </button>
                            );
                        })}
                    </div>
                )}

                {question.type === 'multiple' && question.options && (
                    <div className="space-y-2">
                        <p className="text-xs text-gray-400">Select all that apply</p>
                        {question.options.map((opt) => {
                            const selected = ((answers[currentIdx] as string[]) ?? []).includes(opt.value);
                            return (
                                <button
                                    key={opt.value}
                                    type="button"
                                    onClick={() => !currentResult && toggleMulti(opt.value)}
                                    className={`flex w-full items-center gap-3 rounded-lg border p-3 text-left text-sm transition-colors ${
                                        selected ? 'border-blue-400 bg-blue-50' : 'border-gray-200 hover:border-gray-300'
                                    }`}
                                >
                                    <span
                                        className={`flex h-5 w-5 shrink-0 items-center justify-center rounded border ${
                                            selected ? 'border-blue-500 bg-blue-500' : 'border-gray-300'
                                        }`}
                                    >
                                        {selected && <span className="text-xs text-white">✓</span>}
                                    </span>
                                    {opt.label}
                                </button>
                            );
                        })}
                    </div>
                )}

                {question.type === 'short_answer' && (
                    <textarea
                        className="h-28 w-full resize-none rounded-lg border p-3 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500"
                        placeholder="Type your answer here..."
                        value={(answers[currentIdx] as string) ?? ''}
                        onChange={(e) => setAnswer(e.target.value)}
                        disabled={!!currentResult}
                    />
                )}

                {currentResult && (
                    <div
                        className={`rounded-lg border p-3 text-sm ${
                            currentResult.isCorrect ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50'
                        }`}
                    >
                        <p className="mb-1 font-medium">
                            {currentResult.isCorrect ? '✅ Correct!' : '❌ Not quite'}
                        </p>
                        {currentResult.feedback && <p className="text-gray-700">{currentResult.feedback}</p>}
                        {currentResult.analysis && (
                            <p className="mt-1 text-xs italic text-gray-600">{currentResult.analysis}</p>
                        )}
                    </div>
                )}
            </div>

            {!currentResult && (
                <button
                    type="button"
                    onClick={submitQuestion}
                    disabled={!hasAnswer || grading}
                    className="w-full rounded-lg bg-blue-600 py-3 font-medium text-white transition-colors hover:bg-blue-700 disabled:opacity-50"
                >
                    {grading ? 'Grading...' : 'Submit Answer'}
                </button>
            )}
        </div>
    );
}

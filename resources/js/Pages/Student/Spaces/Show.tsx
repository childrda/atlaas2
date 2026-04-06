import StudentLayout from '@/Layouts/StudentLayout';
import { studentModeLabel } from '@/lib/studentMode';
import type { LearningSpace } from '@/types/models';
import { Link, router, usePage } from '@inertiajs/react';

export default function StudentSpacesShow() {
    const {
        space,
        activeSession,
        classroomLessonAvailable,
        classroomLessonReadyButDisabled,
        multiAgentClassroomEnabled,
    } = usePage().props as {
        space: LearningSpace;
        activeSession: { id: string } | null;
        classroomLessonAvailable?: boolean;
        classroomLessonReadyButDisabled?: boolean;
        multiAgentClassroomEnabled?: boolean;
    };

    function startOrContinue() {
        router.post(`/learn/spaces/${space.id}/sessions`);
    }

    function startClassroomMode() {
        router.post(`/learn/spaces/${space.id}/classroom`);
    }

    return (
        <StudentLayout>
            <div className="mx-auto max-w-2xl px-6 py-10">
                <Link href="/learn/spaces" className="text-sm text-[#1E3A5F] hover:underline">
                    ← Your spaces
                </Link>
                <h1 className="mt-6 text-3xl font-medium text-gray-900">{space.title}</h1>
                <p className="mt-2 text-sm text-gray-500">{space.teacher?.name}</p>
                {space.description && (
                    <p className="mt-6 text-gray-700">{space.description}</p>
                )}
                <p className="mt-4 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                    <span className="font-medium text-slate-900">How ATLAAS helps here: </span>
                    {studentModeLabel(space.student_mode)}
                </p>
                <div className="mt-8">
                    <h2 className="text-sm font-semibold text-gray-900">Goals</h2>
                    <ul className="mt-2 list-inside list-disc text-sm text-gray-700">
                        {(space.goals ?? []).map((g, i) => (
                            <li key={i}>{g}</li>
                        ))}
                    </ul>
                </div>
                <button
                    type="button"
                    onClick={startOrContinue}
                    className="mt-10 w-full rounded-md py-3 text-sm font-medium text-white hover:opacity-95"
                    style={{ backgroundColor: '#1E3A5F' }}
                >
                    {activeSession ? 'Continue session' : 'Start session'}
                </button>

                {multiAgentClassroomEnabled && (
                    <section className="mt-6 rounded-xl border border-amber-200 bg-amber-50/90 p-4">
                        <h2 className="text-sm font-semibold text-amber-950">Multi-agent classroom</h2>
                        {classroomLessonAvailable ? (
                            <>
                                <p className="mt-1 text-xs text-amber-950/85">
                                    A live lesson with multiple AI guides is ready. You can use this instead of or in
                                    addition to chat.
                                </p>
                                <button
                                    type="button"
                                    onClick={startClassroomMode}
                                    className="mt-3 w-full rounded-md border border-[#1E3A5F] bg-white py-3 text-sm font-medium text-[#1E3A5F] hover:bg-white/90"
                                >
                                    Open multi-agent classroom
                                </button>
                            </>
                        ) : (
                            <p className="mt-2 text-sm text-amber-950/90">
                                When your teacher publishes a completed multi-agent lesson for this space, an
                                &quot;Open multi-agent classroom&quot; button will appear in this box.
                            </p>
                        )}
                    </section>
                )}

                {classroomLessonReadyButDisabled && !multiAgentClassroomEnabled && (
                    <p className="mt-3 text-center text-xs text-gray-500">
                        A multi-agent lesson exists for this space, but your teacher has not turned on the classroom
                        experience for students.
                    </p>
                )}
            </div>
        </StudentLayout>
    );
}

import StudentLayout from '@/Layouts/StudentLayout';
import type { LearningSpace } from '@/types/models';
import { Link, router, usePage } from '@inertiajs/react';

export default function StudentSpacesShow() {
    const { space, activeSession, classroomLessonAvailable } = usePage().props as {
        space: LearningSpace;
        activeSession: { id: string } | null;
        classroomLessonAvailable?: boolean;
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
                {classroomLessonAvailable && (
                    <button
                        type="button"
                        onClick={startClassroomMode}
                        className="mt-3 w-full rounded-md border border-[#1E3A5F] bg-white py-3 text-sm font-medium text-[#1E3A5F] hover:bg-slate-50"
                    >
                        Open multi-agent classroom
                    </button>
                )}
            </div>
        </StudentLayout>
    );
}

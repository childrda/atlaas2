import { ToolkitToolIcon } from '@/Components/Toolkit/ToolkitToolIcon';
import TeacherLayout from '@/Layouts/TeacherLayout';
import type { TeacherTool, ToolRun } from '@/types/models';
import { Link, usePage } from '@inertiajs/react';

function categoryLabel(cat: string): string {
    const map: Record<string, string> = {
        lesson_plan: 'Lesson plan',
        rubric: 'Rubric',
        assessment: 'Assessment',
        differentiation: 'Differentiation',
        parent_comm: 'Parent comm',
        feedback: 'Feedback',
        custom: 'Custom',
    };

    return map[cat] ?? cat.replace(/_/g, ' ');
}

export default function ToolkitIndex() {
    const { tools, recentRuns } = usePage().props as {
        tools: TeacherTool[];
        recentRuns: ToolRun[];
    };

    return (
        <TeacherLayout>
            <div className="px-6 py-8">
                <h1 className="text-xl font-medium text-gray-900">Teacher Toolkit</h1>
                <p className="mt-1 text-sm text-gray-500">
                    AI helpers for planning, rubrics, assessments, and more. Output streams live as it generates.
                </p>

                <div className="mt-8 grid gap-8 lg:grid-cols-3">
                    <div className="lg:col-span-2">
                        <h2 className="text-sm font-semibold text-gray-900">Tools</h2>
                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            {tools.map((tool) => (
                                <Link
                                    key={tool.id}
                                    href={`/teach/toolkit/${tool.slug}`}
                                    className="flex gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md"
                                >
                                    <div
                                        className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg"
                                        style={{ backgroundColor: '#EEF2F8', color: '#1E3A5F' }}
                                    >
                                        <ToolkitToolIcon name={tool.icon} className="h-5 w-5" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium text-gray-900">{tool.name}</span>
                                            <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-gray-600">
                                                {categoryLabel(tool.category)}
                                            </span>
                                        </div>
                                        <p className="mt-1 line-clamp-2 text-sm text-gray-500">{tool.description}</p>
                                    </div>
                                </Link>
                            ))}
                        </div>
                    </div>

                    <div>
                        <h2 className="text-sm font-semibold text-gray-900">Recent runs</h2>
                        <div className="mt-4 space-y-2">
                            {recentRuns.length === 0 && (
                                <p className="text-sm text-gray-400">No runs yet. Open a tool to get started.</p>
                            )}
                            {recentRuns.map((run) => (
                                <Link
                                    key={run.id}
                                    href={`/teach/toolkit/${run.tool.slug}`}
                                    className="flex items-center gap-2 rounded-lg border border-gray-100 bg-white px-3 py-2 text-sm hover:border-gray-200"
                                >
                                    <ToolkitToolIcon name={run.tool.icon} className="h-4 w-4 shrink-0 text-[#1E3A5F]" />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate font-medium text-gray-800">{run.tool.name}</p>
                                        <p className="text-xs text-gray-400">
                                            {new Date(run.created_at).toLocaleString()}
                                        </p>
                                    </div>
                                </Link>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </TeacherLayout>
    );
}

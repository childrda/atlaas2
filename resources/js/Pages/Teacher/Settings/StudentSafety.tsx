import TeacherLayout from '@/Layouts/TeacherLayout';
import type { StudentModeSettingsModel } from '@/types/models';
import { Link, router, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';

type DistrictProps = {
    scope: 'district';
    districtDefault: StudentModeSettingsModel;
    schoolSettings: StudentModeSettingsModel[];
};

type SchoolProps = {
    scope: 'school';
    settings: StudentModeSettingsModel;
};

export default function StudentSafety() {
    const page = usePage();
    const { flash } = page.props as { flash?: { success?: string; error?: string } };
    const props = page.props as DistrictProps | SchoolProps;

    const [submitting, setSubmitting] = useState(false);
    const [lmsBusy, setLmsBusy] = useState(false);

    if (props.scope === 'school') {
        const { settings } = props;
        return (
            <TeacherLayout>
                <div className="mx-auto max-w-2xl p-8">
                    <Link href="/teach" className="text-sm text-[#1E3A5F] hover:underline">
                        ← Dashboard
                    </Link>
                    <h1 className="mt-4 text-2xl font-medium text-gray-900">Student safety &amp; modes</h1>
                    <p className="mt-2 text-sm text-gray-600">
                        Controls which interaction modes teachers may assign to learning spaces in your school.
                    </p>
                    {flash?.success && (
                        <div className="mt-6 rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
                            {flash.success}
                        </div>
                    )}
                    <SchoolForm settings={settings} submitting={submitting} setSubmitting={setSubmitting} />
                </div>
            </TeacherLayout>
        );
    }

    const { districtDefault, schoolSettings } = props;

    function submitDistrict(e: FormEvent) {
        e.preventDefault();
        const form = e.target as HTMLFormElement;
        const fd = new FormData(form);
        const district_default = {
            teacher_session_enabled: fd.get('dd_teacher_session') === '1',
            lms_help_enabled: fd.get('dd_lms_help') === '1',
            open_tutor_enabled: fd.get('dd_open_tutor') === '1',
            crisis_counselor_name: String(fd.get('dd_counselor_name') ?? ''),
            crisis_counselor_email: String(fd.get('dd_counselor_email') ?? ''),
            crisis_response_template: String(fd.get('dd_template') ?? ''),
        };
        const schools = schoolSettings.map((s) => ({
            id: s.id,
            teacher_session_enabled: fd.get(`s_${s.id}_teacher`) === '1',
            lms_help_enabled: fd.get(`s_${s.id}_lms`) === '1',
            open_tutor_enabled: fd.get(`s_${s.id}_open`) === '1',
            crisis_counselor_name: String(fd.get(`s_${s.id}_cname`) ?? ''),
            crisis_counselor_email: String(fd.get(`s_${s.id}_cemail`) ?? ''),
            crisis_response_template: String(fd.get(`s_${s.id}_ctemplate`) ?? ''),
        }));
        setSubmitting(true);
        router.put(
            '/teach/settings/student-safety',
            { district_default, schools },
            { preserveScroll: true, onFinish: () => setSubmitting(false) }
        );
    }

    return (
        <TeacherLayout>
            <div className="mx-auto max-w-3xl p-8">
                <Link href="/teach" className="text-sm text-[#1E3A5F] hover:underline">
                    ← Dashboard
                </Link>
                <h1 className="mt-4 text-2xl font-medium text-gray-900">Student safety &amp; modes</h1>
                <p className="mt-2 text-sm text-gray-600">
                    District defaults apply when a school has no override. Teachers only see modes you enable here.
                </p>
                {flash?.success && (
                    <div className="mt-6 rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="mt-6 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">
                        {flash.error}
                    </div>
                )}

                <form onSubmit={submitDistrict} className="mt-8 space-y-10">
                    <section className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                        <h2 className="text-lg font-medium text-gray-900">District default</h2>
                        <ModeCheckboxes
                            names={{ teacher: 'dd_teacher_session', lms: 'dd_lms_help', open: 'dd_open_tutor' }}
                            s={districtDefault}
                        />
                        <CrisisFields
                            nameName="dd_counselor_name"
                            emailName="dd_counselor_email"
                            templateName="dd_template"
                            s={districtDefault}
                        />
                    </section>

                    {schoolSettings.map((s) => (
                        <section key={s.id} className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                            <h2 className="text-lg font-medium text-gray-900">
                                {s.school?.name ?? 'School'}
                            </h2>
                            <ModeCheckboxes
                                names={{ teacher: `s_${s.id}_teacher`, lms: `s_${s.id}_lms`, open: `s_${s.id}_open` }}
                                s={s}
                            />
                            <CrisisFields
                                nameName={`s_${s.id}_cname`}
                                emailName={`s_${s.id}_cemail`}
                                templateName={`s_${s.id}_ctemplate`}
                                s={s}
                            />
                        </section>
                    ))}

                    <button
                        type="submit"
                        disabled={submitting}
                        className="rounded-md px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                        style={{ backgroundColor: '#1E3A5F' }}
                    >
                        Save settings
                    </button>
                </form>

                <section className="mt-12 rounded-lg border border-amber-200 bg-amber-50 p-6">
                    <h2 className="text-lg font-medium text-amber-950">LMS enrollment sync</h2>
                    <p className="mt-2 text-sm text-amber-950/80">
                        District-wide sync for student course rosters. Enable ATLAAS_LMS_SYNC_ENABLED and implement your
                        API client in App\Services\Lms\LmsSyncService.
                    </p>
                    <button
                        type="button"
                        disabled={lmsBusy}
                        onClick={() => {
                            setLmsBusy(true);
                            router.post(
                                '/teach/settings/lms-sync',
                                {},
                                { preserveScroll: true, onFinish: () => setLmsBusy(false) }
                            );
                        }}
                        className="mt-4 rounded-md border border-amber-800/30 bg-white px-4 py-2 text-sm font-medium text-amber-950 disabled:opacity-50"
                    >
                        Run LMS sync now
                    </button>
                </section>
            </div>
        </TeacherLayout>
    );
}

function ModeCheckboxes({
    names,
    s,
}: {
    names: { teacher: string; lms: string; open: string };
    s: StudentModeSettingsModel;
}) {
    return (
        <div className="mt-4 space-y-2 text-sm">
            <label className="flex items-center gap-2">
                <input type="hidden" name={names.teacher} value="0" />
                <input
                    type="checkbox"
                    name={names.teacher}
                    value="1"
                    defaultChecked={s.teacher_session_enabled}
                />
                Teacher session mode
            </label>
            <label className="flex items-center gap-2">
                <input type="hidden" name={names.lms} value="0" />
                <input type="checkbox" name={names.lms} value="1" defaultChecked={s.lms_help_enabled} />
                LMS help mode (uses lms_enrollments)
            </label>
            <label className="flex items-center gap-2">
                <input type="hidden" name={names.open} value="0" />
                <input type="checkbox" name={names.open} value="1" defaultChecked={s.open_tutor_enabled} />
                Open tutor mode (K-12 academic)
            </label>
        </div>
    );
}

function CrisisFields({
    nameName,
    emailName,
    templateName,
    s,
}: {
    nameName: string;
    emailName: string;
    templateName: string;
    s: StudentModeSettingsModel;
}) {
    return (
        <div className="mt-4 space-y-3">
            <div>
                <label className="block text-sm font-medium text-gray-700">Crisis counselor name</label>
                <input
                    name={nameName}
                    defaultValue={s.crisis_counselor_name ?? ''}
                    className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                />
            </div>
            <div>
                <label className="block text-sm font-medium text-gray-700">Crisis counselor email</label>
                <input
                    type="email"
                    name={emailName}
                    defaultValue={s.crisis_counselor_email ?? ''}
                    className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                />
            </div>
            <div>
                <label className="block text-sm font-medium text-gray-700">Custom crisis response (optional)</label>
                <textarea
                    name={templateName}
                    rows={4}
                    defaultValue={s.crisis_response_template ?? ''}
                    className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                />
            </div>
        </div>
    );
}

function SchoolForm({
    settings,
    submitting,
    setSubmitting,
}: {
    settings: StudentModeSettingsModel;
    submitting: boolean;
    setSubmitting: (v: boolean) => void;
}) {
    function submit(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData(e.target as HTMLFormElement);
        setSubmitting(true);
        router.put(
            '/teach/settings/student-safety',
            {
                teacher_session_enabled: fd.get('sh_teacher_session') === '1',
                lms_help_enabled: fd.get('sh_lms_help') === '1',
                open_tutor_enabled: fd.get('sh_open_tutor') === '1',
                crisis_counselor_name: String(fd.get('sh_counselor_name') ?? ''),
                crisis_counselor_email: String(fd.get('sh_counselor_email') ?? ''),
                crisis_response_template: String(fd.get('sh_template') ?? ''),
            },
            { preserveScroll: true, onFinish: () => setSubmitting(false) }
        );
    }

    return (
        <form onSubmit={submit} className="mt-8 space-y-6 rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <ModeCheckboxes
                names={{ teacher: 'sh_teacher_session', lms: 'sh_lms_help', open: 'sh_open_tutor' }}
                s={settings}
            />
            <CrisisFields
                nameName="sh_counselor_name"
                emailName="sh_counselor_email"
                templateName="sh_template"
                s={settings}
            />
            <button
                type="submit"
                disabled={submitting}
                className="rounded-md px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                style={{ backgroundColor: '#1E3A5F' }}
            >
                Save settings
            </button>
        </form>
    );
}

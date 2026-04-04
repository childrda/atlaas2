import StudentLayout from '@/Layouts/StudentLayout';
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function StudentJoin() {
    const form = useForm({ code: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post('/learn/join');
    }

    return (
        <StudentLayout>
            <div className="mx-auto max-w-md px-6 py-16">
                <Link href="/learn" className="text-sm text-[#1E3A5F] hover:underline">
                    ← Dashboard
                </Link>
                <h1 className="mt-6 text-2xl font-medium text-gray-900">Join with code</h1>
                <form onSubmit={submit} className="mt-6 space-y-4">
                    <input
                        value={form.data.code}
                        onChange={(e) => form.setData('code', e.target.value.toUpperCase())}
                        placeholder="Join code"
                        className="w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-sm uppercase"
                        maxLength={10}
                    />
                    {form.errors.code && (
                        <p className="text-sm text-red-600">{form.errors.code}</p>
                    )}
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="w-full rounded-md py-2 text-sm font-medium text-white disabled:opacity-50"
                        style={{ backgroundColor: '#1E3A5F' }}
                    >
                        Join
                    </button>
                </form>
            </div>
        </StudentLayout>
    );
}

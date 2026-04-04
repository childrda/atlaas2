import type { TeacherTool, ToolkitFieldSchema } from '@/types/models';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';

export type ToolInputs = Record<string, string | number | string[] | undefined>;

function initialState(schema: ToolkitFieldSchema[]): ToolInputs {
    const s: ToolInputs = {};
    for (const f of schema) {
        if (f.type === 'checkbox_group') {
            s[f.name] = [];
        } else if (f.type === 'number') {
            s[f.name] = '';
        } else {
            s[f.name] = '';
        }
    }

    return s;
}

export function DynamicToolForm({
    tool,
    disabled,
    fieldErrors,
    onSubmit,
}: {
    tool: TeacherTool;
    disabled?: boolean;
    fieldErrors?: Record<string, string>;
    onSubmit: (inputs: ToolInputs) => void;
}) {
    const schema = tool.input_schema ?? [];
    const [inputs, setInputs] = useState<ToolInputs>(() => initialState(schema));

    const keyedSchema = useMemo(() => schema, [schema]);

    function setField(name: string, value: string | number | string[]) {
        setInputs((prev) => ({ ...prev, [name]: value }));
    }

    function toggleCheckbox(name: string, option: string) {
        setInputs((prev) => {
            const cur = (prev[name] as string[] | undefined) ?? [];
            const next = cur.includes(option) ? cur.filter((x) => x !== option) : [...cur, option];

            return { ...prev, [name]: next };
        });
    }

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        onSubmit(inputs);
    }

    return (
        <form onSubmit={handleSubmit} className="space-y-4">
            {keyedSchema.map((field: ToolkitFieldSchema) => {
                const err = fieldErrors?.[field.name];
                const baseClass =
                    'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-[#1E3A5F] focus:outline-none focus:ring-1 focus:ring-[#1E3A5F]';

                return (
                    <div key={field.name}>
                        <label className="block text-sm font-medium text-gray-700" htmlFor={field.name}>
                            {field.label}
                            {field.required && <span className="text-red-500"> *</span>}
                        </label>

                        {field.type === 'text' && (
                            <input
                                id={field.name}
                                type="text"
                                value={(inputs[field.name] as string) ?? ''}
                                onChange={(ev) => setField(field.name, ev.target.value)}
                                placeholder={field.placeholder}
                                className={baseClass}
                                disabled={disabled}
                            />
                        )}

                        {field.type === 'textarea' && (
                            <textarea
                                id={field.name}
                                rows={4}
                                value={(inputs[field.name] as string) ?? ''}
                                onChange={(ev) => setField(field.name, ev.target.value)}
                                placeholder={field.placeholder}
                                className={baseClass}
                                disabled={disabled}
                            />
                        )}

                        {field.type === 'number' && (
                            <input
                                id={field.name}
                                type="number"
                                value={inputs[field.name] === '' ? '' : String(inputs[field.name] ?? '')}
                                onChange={(ev) =>
                                    setField(field.name, ev.target.value === '' ? '' : Number(ev.target.value))
                                }
                                placeholder={field.placeholder}
                                className={baseClass}
                                disabled={disabled}
                            />
                        )}

                        {field.type === 'select' && field.options && (
                            <select
                                id={field.name}
                                value={(inputs[field.name] as string) ?? ''}
                                onChange={(ev) => setField(field.name, ev.target.value)}
                                className={baseClass}
                                disabled={disabled}
                            >
                                <option value="">Select…</option>
                                {field.options.map((opt) => (
                                    <option key={opt} value={opt}>
                                        {opt}
                                    </option>
                                ))}
                            </select>
                        )}

                        {field.type === 'checkbox_group' && field.options && (
                            <div className="mt-2 space-y-2">
                                {field.options.map((opt) => {
                                    const selected = ((inputs[field.name] as string[]) ?? []).includes(opt);

                                    return (
                                        <label key={opt} className="flex items-center gap-2 text-sm text-gray-700">
                                            <input
                                                type="checkbox"
                                                checked={selected}
                                                onChange={() => toggleCheckbox(field.name, opt)}
                                                disabled={disabled}
                                                className="rounded border-gray-300 text-[#1E3A5F] focus:ring-[#1E3A5F]"
                                            />
                                            {opt}
                                        </label>
                                    );
                                })}
                            </div>
                        )}

                        {err && <p className="mt-1 text-sm text-red-600">{err}</p>}
                    </div>
                );
            })}

            <button
                type="submit"
                disabled={disabled}
                className="w-full rounded-lg bg-[#1E3A5F] py-2.5 text-sm font-medium text-white hover:bg-[#162d4a] disabled:opacity-50"
            >
                {disabled ? 'Running…' : 'Run tool'}
            </button>
        </form>
    );
}

import { useEffect, useState } from 'react';

interface WhiteboardCanvasProps {
    sessionId: string;
    pollMs?: number;
}

export default function WhiteboardCanvas({ sessionId, pollMs = 500 }: WhiteboardCanvasProps) {
    const [state, setState] = useState<{ elements: Record<string, unknown>[]; open: boolean }>({
        elements: [],
        open: false,
    });

    useEffect(() => {
        let cancelled = false;

        const load = async () => {
            try {
                const res = await fetch(`/learn/classroom/${sessionId}/whiteboard`, {
                    headers: { Accept: 'application/json' },
                });
                if (!res.ok) return;
                const data = await res.json();
                if (!cancelled) {
                    setState({ elements: data.elements ?? [], open: !!data.open });
                }
            } catch {
                /* ignore */
            }
        };

        void load();

        const Echo = window.Echo;
        if (Echo) {
            const channel = Echo.private(`classroom.${sessionId}`);

            channel.listen(
                '.whiteboard.updated',
                (payload: { elements?: Record<string, unknown>[]; open?: boolean }) => {
                    setState({
                        elements: payload.elements ?? [],
                        open: !!payload.open,
                    });
                },
            );

            return () => {
                cancelled = true;
                channel.stopListening('.whiteboard.updated');
            };
        }

        const id = setInterval(load, pollMs);

        return () => {
            cancelled = true;
            clearInterval(id);
        };
    }, [sessionId, pollMs]);

    if (!state.open && state.elements.length === 0) {
        return (
            <div className="flex aspect-[1000/562] w-full items-center justify-center rounded-lg border border-dashed border-gray-200 bg-gray-50 text-xs text-gray-400">
                Whiteboard hidden
            </div>
        );
    }

    return (
        <div
            className="relative w-full overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
            style={{ aspectRatio: '1000/562' }}
        >
            {state.elements.map((el, idx) => (
                <WhiteboardElement key={String(el.id ?? idx)} element={el} index={idx} />
            ))}
        </div>
    );
}

function WhiteboardElement({ element, index }: { element: Record<string, unknown>; index: number }) {
    const left = Number(element.left ?? 0);
    const top = Number(element.top ?? 0);
    const width = Number(element.width ?? 100);
    const height = Number(element.height ?? 40);

    const baseStyle: React.CSSProperties = {
        position: 'absolute',
        left: `${(left / 1000) * 100}%`,
        top: `${(top / 562) * 100}%`,
        width: `${(width / 1000) * 100}%`,
        height: `${(height / 562) * 100}%`,
        animationDelay: `${index * 50}ms`,
        transition: 'opacity 450ms ease, transform 450ms ease, filter 450ms ease',
        opacity: 1,
        transform: 'scale(1) translateY(0)',
        filter: 'blur(0)',
    };

    const type = String(element.type ?? '');

    if (type === 'text') {
        return (
            <div style={baseStyle} className="overflow-hidden text-xs">
                <div
                    className="h-full w-full p-1"
                    style={{ color: String(element.color ?? '#333') }}
                    dangerouslySetInnerHTML={{ __html: String(element.content ?? '') }}
                />
            </div>
        );
    }

    if (type === 'shape') {
        const shape = String(element.shape ?? 'rectangle');
        const fill = String(element.fill_color ?? '#5b9bd5');
        return (
            <div style={baseStyle} className="flex items-center justify-center overflow-hidden">
                {shape === 'circle' ? (
                    <div className="aspect-square h-full rounded-full" style={{ backgroundColor: fill }} />
                ) : (
                    <div className="h-full w-full" style={{ backgroundColor: fill, borderRadius: 4 }} />
                )}
            </div>
        );
    }

    if (type === 'latex') {
        return (
            <div style={baseStyle} className="flex items-center justify-center overflow-hidden font-mono text-[10px]">
                {String(element.latex ?? '')}
            </div>
        );
    }

    if (type === 'chart') {
        const data = (element.data as { labels?: string[]; series?: number[][] }) ?? {};
        const labels = data.labels ?? [];
        const series = data.series ?? [[]];
        const values = series[0] ?? [];
        const max = Math.max(...values, 1);
        return (
            <div style={baseStyle} className="flex items-end gap-0.5 p-1">
                {values.map((v: number, i: number) => (
                    <div key={i} className="flex flex-1 flex-col items-center">
                        <div
                            className="w-full rounded-sm bg-blue-400"
                            style={{ height: `${(v / max) * 100}%`, minHeight: 2 }}
                        />
                        <span className="truncate text-[8px] text-gray-500">{labels[i]}</span>
                    </div>
                ))}
            </div>
        );
    }

    if (type === 'table') {
        const rows = (element.data as string[][]) ?? [];
        return (
            <div style={baseStyle} className="overflow-auto text-[8px]">
                <table className="w-full border-collapse">
                    {rows.map((row, ri) => (
                        <tr key={ri} className={ri === 0 ? 'bg-gray-100 font-bold' : ''}>
                            {row.map((cell, ci) => (
                                <td key={ci} className="border border-gray-300 px-0.5 py-0.5 text-center">
                                    {cell}
                                </td>
                            ))}
                        </tr>
                    ))}
                </table>
            </div>
        );
    }

    if (type === 'line') {
        const sx = Number(element.start_x ?? 0);
        const sy = Number(element.start_y ?? 0);
        const ex = Number(element.end_x ?? 0);
        const ey = Number(element.end_y ?? 0);
        const len = Math.hypot(ex - sx, ey - sy);
        const ang = (Math.atan2(ey - sy, ex - sx) * 180) / Math.PI;
        return (
            <div
                style={{
                    ...baseStyle,
                    left: `${(sx / 1000) * 100}%`,
                    top: `${(sy / 562) * 100}%`,
                    width: `${(len / 1000) * 100}%`,
                    height: 2,
                    transformOrigin: '0 50%',
                    transform: `rotate(${ang}deg)`,
                    backgroundColor: String(element.color ?? '#333'),
                }}
            />
        );
    }

    return null;
}

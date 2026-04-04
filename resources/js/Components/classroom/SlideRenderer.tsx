import { useEffect, useState } from 'react';

interface SlideRendererProps {
    slide: {
        background?: { type: string; color?: string };
        elements: Record<string, unknown>[];
    };
    spotlightId: string | null;
    laserTarget: string | null;
}

export default function SlideRenderer({ slide, spotlightId, laserTarget }: SlideRendererProps) {
    const bgColor = slide.background?.color ?? '#ffffff';
    const elements = slide.elements ?? [];

    return (
        <div
            className="relative w-full overflow-hidden"
            style={{ aspectRatio: '960/540', backgroundColor: bgColor }}
        >
            {spotlightId && <SpotlightOverlay elementId={spotlightId} elements={elements} />}
            {elements.map((el: Record<string, unknown>) => (
                <SlideElementView key={String(el.id)} element={el} isLasered={laserTarget === el.id} />
            ))}
        </div>
    );
}

function SlideElementView({
    element: el,
    isLasered,
}: {
    element: Record<string, unknown>;
    isLasered: boolean;
}) {
    const left = Number(el.left ?? 0);
    const top = Number(el.top ?? 0);
    const width = Number(el.width ?? 0);
    const height = Number(el.height ?? 0);

    const style: React.CSSProperties = {
        position: 'absolute',
        left: `${(left / 960) * 100}%`,
        top: `${(top / 540) * 100}%`,
        width: `${(width / 960) * 100}%`,
        height: `${(height / 540) * 100}%`,
        outline: isLasered ? '2px solid #ff0000' : undefined,
        transition: 'outline 0.2s',
    };

    switch (el.type) {
        case 'text':
            return (
                <div style={style}>
                    <div
                        className="h-full w-full overflow-hidden"
                        style={{ padding: '10px', color: (el.defaultColor as string) ?? '#333' }}
                        dangerouslySetInnerHTML={{ __html: String(el.content ?? '') }}
                    />
                </div>
            );
        case 'shape':
            return (
                <div style={style}>
                    <svg
                        viewBox={String(el.viewBox ?? '0 0 1000 1000')}
                        className="h-full w-full"
                        preserveAspectRatio="none"
                    >
                        <path d={String(el.path ?? '')} fill={String(el.fill ?? '#5b9bd5')} />
                    </svg>
                </div>
            );
        case 'latex':
            return <LatexSlideBlock element={el} style={style} />;
        case 'chart':
            return <ChartSlideBlock element={el} style={style} />;
        case 'table':
            return <TableSlideBlock element={el} style={style} />;
        case 'line':
            return (
                <div style={style}>
                    <div
                        className="w-full"
                        style={{
                            borderTop: `${Number(el.width ?? 2)}px ${String(el.style ?? 'solid')} ${String(el.color ?? '#ccc')}`,
                            marginTop: '50%',
                        }}
                    />
                </div>
            );
        case 'image':
            return (
                <div style={style}>
                    <img src={String(el.src ?? '')} alt="" className="h-full w-full object-cover" />
                </div>
            );
        default:
            return null;
    }
}

function LatexSlideBlock({ element: el, style }: { element: Record<string, unknown>; style: React.CSSProperties }) {
    const [html, setHtml] = useState('');

    useEffect(() => {
        const katex = (window as unknown as { katex?: { renderToString: (s: string, o: object) => string } }).katex;
        const latex = String(el.latex ?? '');
        if (katex) {
            try {
                setHtml(
                    katex.renderToString(latex, {
                        throwOnError: false,
                        displayMode: true,
                    })
                );
            } catch {
                setHtml(`<span>${latex}</span>`);
            }
        } else {
            setHtml(`<span class="font-mono text-sm">${latex}</span>`);
        }
    }, [el.latex]);

    return (
        <div
            style={{
                ...style,
                display: 'flex',
                alignItems: 'center',
                justifyContent: el.align === 'left' ? 'flex-start' : 'center',
            }}
        >
            <div
                dangerouslySetInnerHTML={{ __html: html }}
                style={{ color: String(el.color ?? '#000') }}
            />
        </div>
    );
}

function ChartSlideBlock({ element: el, style }: { element: Record<string, unknown>; style: React.CSSProperties }) {
    const data = (el.data as { labels?: string[]; series?: number[][] }) ?? {};
    const labels = data.labels ?? [];
    const series = data.series ?? [[]];
    const colors = (el.themeColors as string[]) ?? ['#5b9bd5', '#ed7d31', '#a9d18e'];

    if (el.chartType === 'pie' || el.chartType === 'ring') {
        return (
            <div style={style} className="flex items-center justify-center">
                <span className="text-xs text-gray-400">
                    [{String(el.chartType)} chart: {labels.join(', ')}]
                </span>
            </div>
        );
    }

    const values = series[0] ?? [];
    const max = Math.max(...values, 1);

    return (
        <div style={style} className="flex items-end gap-1 p-2">
            {values.map((v: number, i: number) => (
                <div key={i} className="flex flex-1 flex-col items-center gap-1">
                    <div
                        className="w-full rounded-sm transition-all"
                        style={{
                            height: `${(v / max) * 80}%`,
                            backgroundColor: colors[0],
                            minHeight: 2,
                        }}
                    />
                    <span className="w-full truncate text-center text-xs text-gray-500">{labels[i] ?? ''}</span>
                </div>
            ))}
        </div>
    );
}

function TableSlideBlock({ element: el, style }: { element: Record<string, unknown>; style: React.CSSProperties }) {
    const rows = (el.data as { id?: string; colspan?: number; rowspan?: number; text?: string }[][]) ?? [];

    return (
        <div style={style} className="overflow-hidden">
            <table className="h-full w-full border-collapse text-xs">
                {rows.map((row, rowIdx) => (
                    <tr key={rowIdx} className={rowIdx === 0 ? 'bg-gray-100 font-semibold' : ''}>
                        {row.map((cell) => (
                            <td
                                key={cell.id ?? `${rowIdx}-${cell.text}`}
                                colSpan={cell.colspan ?? 1}
                                rowSpan={cell.rowspan ?? 1}
                                className="border border-gray-200 px-2 py-1 text-center"
                            >
                                {cell.text}
                            </td>
                        ))}
                    </tr>
                ))}
            </table>
        </div>
    );
}

function SpotlightOverlay({ elementId, elements }: { elementId: string; elements: Record<string, unknown>[] }) {
    const el = elements.find((e) => e.id === elementId);
    if (!el) return null;

    const left = Number(el.left ?? 0);
    const top = Number(el.top ?? 0);
    const width = Number(el.width ?? 0);
    const height = Number(el.height ?? 0);

    return (
        <div className="pointer-events-none absolute inset-0" style={{ zIndex: 10 }}>
            <div className="absolute inset-0 bg-black opacity-50" />
            <div
                className="absolute bg-transparent"
                style={{
                    left: `${(left / 960) * 100}%`,
                    top: `${(top / 540) * 100}%`,
                    width: `${(width / 960) * 100}%`,
                    height: `${(height / 540) * 100}%`,
                    boxShadow: '0 0 0 9999px rgba(0,0,0,0.5)',
                    borderRadius: '4px',
                }}
            />
        </div>
    );
}

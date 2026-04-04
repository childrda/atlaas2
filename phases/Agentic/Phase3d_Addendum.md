# ATLAAS — Phase 3d Addendum: Precise Implementation Details
## Read this alongside Phase3d_MultiAgent_Classroom.md
## Source: Direct OpenMAIC source code analysis — corrections and enrichments

---

## What this document adds

The main Phase 3d build script covers architecture and structure. This addendum
adds precise implementation details discovered from reading the actual source:
slide element types and their exact schemas, quiz phase flow, text height tables,
the slide renderer component, the full quiz flow UI, and corrected slide content
generation prompts. Cursor should read both documents before implementing.

---

## 1 — Slide element types: complete schemas

The slide generator produces these six element types. The PHP service must
understand all of them to generate correct JSON for the LLM prompt, and the
React renderer must handle all of them. Canvas size is **960 × 540px**.

### TextElement
```json
{
  "id": "text_001",
  "type": "text",
  "left": 60,
  "top": 80,
  "width": 840,
  "height": 58,
  "content": "<p style=\"font-size: 32px;\"><strong>Title Here</strong></p>",
  "defaultFontName": "",
  "defaultColor": "#333333"
}
```
- `content` is HTML: supported tags are `<p>`, `<span>`, `<strong>`, `<em>`, `<u>`
- Multiple lines = multiple `<p>` tags
- CRITICAL: Never put LaTeX syntax inside content — it renders as raw text. Use LatexElement instead.
- Internal padding: 10px all sides. Actual text area = (width-20) × (height-20).
- Height must come from this table (line-height 1.5):

| Font size | 1 line | 2 lines | 3 lines | 4 lines | 5 lines |
|-----------|--------|---------|---------|---------|---------|
| 14px      | 43     | 64      | 85      | 106     | 127     |
| 16px      | 46     | 70      | 94      | 118     | 142     |
| 18px      | 49     | 76      | 103     | 130     | 157     |
| 20px      | 52     | 82      | 112     | 142     | 172     |
| 24px      | 58     | 94      | 130     | 166     | 202     |
| 28px      | 64     | 106     | 148     | 190     | 232     |
| 32px      | 70     | 118     | 166     | 214     | 262     |

### LatexElement
```json
{
  "id": "latex_001",
  "type": "latex",
  "left": 100,
  "top": 200,
  "width": 400,
  "height": 80,
  "latex": "E = mc^2",
  "color": "#000000",
  "align": "center"
}
```
- `height` is the preferred vertical size; `width` is the maximum horizontal bound
- System auto-computes actual rendered width from the formula's aspect ratio
- Height guide: simple equations 50-80px, fractions 60-100px, integrals 60-100px,
  summations 80-120px, matrices 100-180px
- Do NOT generate `path`, `viewBox`, `strokeWidth`, or `fixedRatio` — system fills these
- Use KaTeX syntax. Standard LaTeX math commands work. `\text{}` for inline English text.

### ChartElement
```json
{
  "id": "chart_001",
  "type": "chart",
  "left": 100,
  "top": 150,
  "width": 500,
  "height": 300,
  "chartType": "bar",
  "data": {
    "labels": ["Q1", "Q2", "Q3", "Q4"],
    "legends": ["Revenue", "Costs"],
    "series": [[100, 120, 140, 160], [80, 90, 100, 110]]
  },
  "themeColors": ["#5b9bd5", "#ed7d31"]
}
```
- Chart types: `bar` (vertical bars), `column` (horizontal bars), `line`, `pie`,
  `ring`, `area`, `radar`, `scatter`
- `labels`: x-axis labels (or pie slice labels)
- `legends`: one name per series
- `series`: 2D array, one array per legend entry

### TableElement
```json
{
  "id": "table_001",
  "type": "table",
  "left": 100,
  "top": 150,
  "width": 600,
  "height": 180,
  "colWidths": [0.25, 0.25, 0.25, 0.25],
  "data": [
    [{"id":"c1","colspan":1,"rowspan":1,"text":"Header 1"},{"id":"c2","colspan":1,"rowspan":1,"text":"Header 2"}],
    [{"id":"c3","colspan":1,"rowspan":1,"text":"Row 1 A"},{"id":"c4","colspan":1,"rowspan":1,"text":"Row 1 B"}]
  ],
  "outline": {"width": 2, "style": "solid", "color": "#eeece1"}
}
```
- `colWidths`: array of ratios summing to 1.0
- First row = header row
- Cell `text` is plain text only — no LaTeX in table cells

### ShapeElement (for backgrounds and decorative shapes)
```json
{
  "id": "shape_001",
  "type": "shape",
  "left": 0,
  "top": 0,
  "width": 960,
  "height": 80,
  "fixedRatio": false,
  "viewBox": "0 0 200 200",
  "path": "M 0 0 L 200 0 L 200 200 L 0 200 Z",
  "fill": "#1E3A5F"
}
```
- Used for colored background bars, decorative blocks
- `path` uses SVG path syntax within the `viewBox` coordinate space
- Common paths: rectangle `M 0 0 L 1000 0 L 1000 1000 L 0 1000 Z`,
  circle `M 500 0 A 500 500 0 1 1 499 0 Z`

### LineElement
```json
{
  "id": "line_001",
  "type": "line",
  "left": 60,
  "top": 200,
  "width": 840,
  "height": 2,
  "style": "solid",
  "color": "#cccccc"
}
```
- Used for dividers and separators
- `style`: `solid` or `dashed`

---

## 2 — Updated slide content system prompt

Replace the `slideContentSystemPrompt()` method in `LessonGeneratorService.php`
with this more precise version that teaches the LLM all element types:

```php
private function slideContentSystemPrompt(): string
{
    return <<<'PROMPT'
You are an educational slide designer for K-12 classrooms.

## Canvas
960 × 540 px. Safe margins: left ≥ 60, right ≤ 900, top ≥ 50, bottom ≤ 490.

## Slide philosophy
Slides are visual aids — keywords and bullet points only. Full sentences go in
the teacher's spoken narration, NOT on the slide. Max ~20 words per bullet.
Never name the teacher on slides ("Teacher's Tips" → "Tips").

## Text height table (use ONLY these values)
| Font | 1 line | 2 lines | 3 lines | 4 lines |
|------|--------|---------|---------|---------|
| 14px | 43     | 64      | 85      | 106     |
| 16px | 46     | 70      | 94      | 118     |
| 18px | 49     | 76      | 103     | 130     |
| 20px | 52     | 82      | 112     | 142     |
| 24px | 58     | 94      | 130     | 166     |
| 28px | 64     | 106     | 148     | 190     |
| 32px | 70     | 118     | 166     | 214     |

## Element types

TextElement: {"id":"text_N","type":"text","left":N,"top":N,"width":N,"height":N,
  "content":"<p style=\"font-size:Npx\">text</p>","defaultFontName":"","defaultColor":"#333"}
  CRITICAL: Never put LaTeX (\\frac, \\int, x^2) in content — use LatexElement instead.

LatexElement: {"id":"latex_N","type":"latex","left":N,"top":N,"width":N,"height":N,
  "latex":"E=mc^2","color":"#000000","align":"center"}
  height 50-80 for simple, 60-100 for fractions/integrals. Do NOT add path/viewBox.

ChartElement: {"id":"chart_N","type":"chart","left":N,"top":N,"width":N,"height":N,
  "chartType":"bar|line|pie|area|radar","data":{"labels":[...],"legends":[...],"series":[[...]]},"themeColors":["#hex"]}

TableElement: {"id":"table_N","type":"table","left":N,"top":N,"width":N,"height":N,
  "colWidths":[0.33,0.33,0.34],"data":[[{"id":"c1","colspan":1,"rowspan":1,"text":"Header"}]],"outline":{"width":2,"style":"solid","color":"#eeece1"}}
  Plain text only in table cells — no LaTeX syntax.

ShapeElement: {"id":"shape_N","type":"shape","left":N,"top":N,"width":N,"height":N,
  "fixedRatio":false,"viewBox":"0 0 1000 1000","path":"M 0 0 L 1000 0 L 1000 1000 L 0 1000 Z","fill":"#hex"}
  Use for background bars and colored blocks behind text.

## Output format
{"background":{"type":"solid","color":"#ffffff"},"elements":[...]}
JSON only — no explanation, no code fences.
PROMPT;
}
```

---

## 3 — Updated slide content user prompt

Add cross-scene context so the teacher agent doesn't re-greet on every slide.
Update `slideContentUserPrompt()` in `LessonGeneratorService.php`:

```php
private function slideContentUserPrompt(LessonScene $scene, array $outline): string
{
    $lesson    = $scene->lesson;
    $allScenes = $lesson->scenes()->orderBy('sequence_order')->get();
    $position  = $allScenes->search(fn($s) => $s->id === $scene->id) + 1;
    $total     = $allScenes->count();
    $allTitles = $allScenes->pluck('title')->implode(', ');

    $posNote = match(true) {
        $position === 1      => "FIRST scene: open with greeting and introduction.",
        $position === $total => "LAST scene: summarize and close the lesson.",
        default              => "Scene {$position} of {$total}: continue naturally, do NOT re-greet.",
    };

    $keyPoints = implode("\n- ", $outline['keyPoints'] ?? []);

    return <<<TEXT
Title: {$scene->title}
Description: {$outline['description'] ?? ''}
Key points:
- {$keyPoints}

Position: {$posNote}
All scene titles: {$allTitles}

Generate slide elements JSON only.
TEXT;
}
```

---

## 4 — Updated action sequence generation (cross-scene continuity)

The action sequence for each scene needs position context so agents don't
repeat greetings. Update `actionSequenceUserPrompt()`:

```php
private function actionSequenceUserPrompt(LessonScene $scene, array $content): string
{
    $lesson    = $scene->lesson;
    $allScenes = $lesson->scenes()->orderBy('sequence_order')->get();
    $position  = $allScenes->search(fn($s) => $s->id === $scene->id) + 1;
    $total     = $allScenes->count();
    $outline   = $scene->outline_data ?? [];
    $keyPoints = implode("\n- ", $outline['keyPoints'] ?? []);

    $posNote = match(true) {
        $position === 1      => "FIRST scene — greet students and introduce the lesson.",
        $position === $total => "LAST scene — summarize the whole lesson and close warmly.",
        default              => "Scene {$position} of {$total} — continue naturally, NO greeting.",
    };

    $continuityNote = "All scenes are one continuous class session. Never say 'last class' or 'previous session'.";

    if ($scene->scene_type === 'slide') {
        $elementSummary = collect($content['elements'] ?? [])
            ->map(fn($el) => "[id:{$el['id']}] {$el['type']}: " . mb_substr(strip_tags($el['content'] ?? $el['latex'] ?? ''), 0, 50))
            ->implode("\n");
        return "Title: {$scene->title}\nKey points:\n- {$keyPoints}\n\nSlide elements:\n{$elementSummary}\n\n{$posNote}\n{$continuityNote}\n\nGenerate action sequence JSON array (5-10 items).";
    }

    if ($scene->scene_type === 'quiz') {
        $questions = collect($content['questions'] ?? [])
            ->map(fn($q, $i) => ($i+1).". [{$q['type']}] {$q['question']}")
            ->implode("\n");
        return "Quiz: {$scene->title}\nQuestions:\n{$questions}\n\n{$posNote}\n{$continuityNote}\n\nGenerate brief intro speech + one discussion action at the end. JSON array only.";
    }

    return "Scene: {$scene->title}\nKey points:\n- {$keyPoints}\n\n{$posNote}\n{$continuityNote}\n\nGenerate action sequence JSON array.";
}
```

---

## 5 — Slide renderer React component (replace placeholder)

The `SlideRenderer` component in `ClassroomContent` needs to handle all six
element types. Replace the placeholder in the Classroom page with this:

Create `resources/js/components/classroom/SlideRenderer.tsx`:

```tsx
import { useEffect, useState } from 'react'

interface SlideRendererProps {
  slide: {
    background?: { type: string; color?: string }
    elements: any[]
  }
  spotlightId: string | null
  laserTarget: string | null
}

export default function SlideRenderer({ slide, spotlightId, laserTarget }: SlideRendererProps) {
  const bgColor = slide.background?.color ?? '#ffffff'

  return (
    // 960×540 coordinate space, scaled to container
    <div className="relative w-full" style={{ aspectRatio: '960/540', backgroundColor: bgColor, overflow: 'hidden' }}>
      {/* Spotlight overlay */}
      {spotlightId && <SpotlightOverlay elementId={spotlightId} elements={slide.elements} />}

      {/* Elements */}
      {slide.elements.map((el: any) => (
        <SlideElement key={el.id} element={el} isLasered={laserTarget === el.id} />
      ))}
    </div>
  )
}

function SlideElement({ element: el, isLasered }: { element: any; isLasered: boolean }) {
  // Convert 960×540 space to percentages
  const style: React.CSSProperties = {
    position: 'absolute',
    left:   `${(el.left / 960) * 100}%`,
    top:    `${(el.top  / 540) * 100}%`,
    width:  `${(el.width / 960) * 100}%`,
    height: `${(el.height / 540) * 100}%`,
    outline: isLasered ? '2px solid #ff0000' : undefined,
    transition: 'outline 0.2s',
  }

  switch (el.type) {
    case 'text':
      return (
        <div style={style}>
          <div
            className="w-full h-full overflow-hidden"
            style={{ padding: '10px', color: el.defaultColor ?? '#333' }}
            dangerouslySetInnerHTML={{ __html: el.content ?? '' }}
          />
        </div>
      )

    case 'shape':
      return (
        <div style={style}>
          <svg viewBox={el.viewBox ?? '0 0 1000 1000'} className="w-full h-full" preserveAspectRatio="none">
            <path d={el.path} fill={el.fill ?? '#5b9bd5'} />
          </svg>
        </div>
      )

    case 'latex':
      return <LatexElement element={el} style={style} />

    case 'chart':
      return <ChartElement element={el} style={style} />

    case 'table':
      return <TableElement element={el} style={style} />

    case 'line':
      return (
        <div style={style}>
          <div className="w-full" style={{
            borderTop: `${el.width ?? 2}px ${el.style ?? 'solid'} ${el.color ?? '#ccc'}`,
            marginTop: '50%',
          }} />
        </div>
      )

    case 'image':
      return (
        <div style={style}>
          {/* Images resolved via Wikimedia/Unsplash per Phase 3b */}
          <img src={el.src ?? ''} alt="" className="w-full h-full object-cover" />
        </div>
      )

    default:
      return null
  }
}

function LatexElement({ element: el, style }: { element: any; style: React.CSSProperties }) {
  const [html, setHtml] = useState('')

  useEffect(() => {
    // Use KaTeX if available (injected via CDN), otherwise show raw LaTeX
    if ((window as any).katex) {
      try {
        const rendered = (window as any).katex.renderToString(el.latex ?? '', {
          throwOnError: false,
          displayMode: true,
        })
        setHtml(rendered)
      } catch {
        setHtml(`<span>${el.latex}</span>`)
      }
    } else {
      setHtml(`<span class="font-mono text-sm">${el.latex}</span>`)
    }
  }, [el.latex])

  return (
    <div style={{ ...style, display: 'flex', alignItems: 'center', justifyContent: el.align === 'left' ? 'flex-start' : 'center' }}>
      <div dangerouslySetInnerHTML={{ __html: html }} style={{ color: el.color ?? '#000' }} />
    </div>
  )
}

function ChartElement({ element: el, style }: { element: any; style: React.CSSProperties }) {
  // Minimal bar chart implementation using SVG
  // For production, use recharts (already in package.json)
  const data   = el.data ?? {}
  const labels = data.labels ?? []
  const series = data.series ?? [[]]
  const colors = el.themeColors ?? ['#5b9bd5', '#ed7d31', '#a9d18e']

  if (el.chartType === 'pie' || el.chartType === 'ring') {
    return (
      <div style={style} className="flex items-center justify-center">
        <span className="text-xs text-gray-400">[{el.chartType} chart: {labels.join(', ')}]</span>
      </div>
    )
  }

  // Simple bar chart
  const values = series[0] ?? []
  const max    = Math.max(...values, 1)

  return (
    <div style={style} className="flex items-end gap-1 p-2">
      {values.map((v: number, i: number) => (
        <div key={i} className="flex flex-col items-center flex-1 gap-1">
          <div
            className="w-full rounded-sm transition-all"
            style={{ height: `${(v / max) * 80}%`, backgroundColor: colors[0], minHeight: 2 }}
          />
          <span className="text-xs text-gray-500 truncate w-full text-center">{labels[i] ?? ''}</span>
        </div>
      ))}
    </div>
  )
}

function TableElement({ element: el, style }: { element: any; style: React.CSSProperties }) {
  const rows = el.data ?? []

  return (
    <div style={style} className="overflow-hidden">
      <table className="w-full h-full border-collapse text-xs">
        {rows.map((row: any[], rowIdx: number) => (
          <tr key={rowIdx} className={rowIdx === 0 ? 'bg-gray-100 font-semibold' : ''}>
            {row.map((cell: any) => (
              <td
                key={cell.id}
                colSpan={cell.colspan ?? 1}
                rowSpan={cell.rowspan ?? 1}
                className="border border-gray-200 px-2 py-1 text-center"
                style={{ color: cell.style?.color, fontSize: cell.style?.fontsize }}
              >
                {cell.text}
              </td>
            ))}
          </tr>
        ))}
      </table>
    </div>
  )
}

function SpotlightOverlay({ elementId, elements }: { elementId: string; elements: any[] }) {
  const el = elements.find(e => e.id === elementId)
  if (!el) return null

  // Spotlight: dim everything, highlight one element
  return (
    <div className="absolute inset-0 pointer-events-none" style={{ zIndex: 10 }}>
      {/* Dark overlay */}
      <div className="absolute inset-0 bg-black opacity-50" />
      {/* Clear hole over the element */}
      <div
        className="absolute bg-transparent"
        style={{
          left:   `${(el.left / 960) * 100}%`,
          top:    `${(el.top  / 540) * 100}%`,
          width:  `${(el.width / 960) * 100}%`,
          height: `${(el.height / 540) * 100}%`,
          boxShadow: '0 0 0 9999px rgba(0,0,0,0.5)',
          borderRadius: '4px',
        }}
      />
    </div>
  )
}
```

Add KaTeX to the layout blade (or load it once when classroom starts):
```html
<!-- In resources/views/app.blade.php <head> -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">
<script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js"></script>
```

---

## 6 — Full quiz UI component

Replace the basic `QuizWidget` placeholder with this complete implementation
that handles all question types and shows results after grading.

Create `resources/js/components/classroom/QuizWidget.tsx`:

```tsx
import { useState } from 'react'

interface QuizQuestion {
  id: string
  type: 'single' | 'multiple' | 'short_answer'
  question: string
  options?: { label: string; value: string }[]
  answer?: string[]
  analysis?: string
  points?: number
}

interface QuizWidgetProps {
  questions: QuizQuestion[]
  sceneId: string
  sessionId: string
  onComplete?: (results: QuizResult[]) => void
}

interface QuizResult {
  questionIndex: number
  isCorrect: boolean
  score: number
  maxScore: number
  feedback: string
  analysis?: string
}

type Phase = 'intro' | 'answering' | 'grading' | 'results'

export default function QuizWidget({ questions, sceneId, sessionId, onComplete }: QuizWidgetProps) {
  const [phase, setPhase]           = useState<Phase>('intro')
  const [currentIdx, setCurrentIdx] = useState(0)
  const [answers, setAnswers]       = useState<Record<number, string | string[]>>({})
  const [results, setResults]       = useState<QuizResult[]>([])
  const [grading, setGrading]       = useState(false)

  const question = questions[currentIdx]
  const totalPts = questions.reduce((s, q) => s + (q.points ?? 10), 0)

  const setAnswer = (value: string | string[]) => {
    setAnswers(prev => ({ ...prev, [currentIdx]: value }))
  }

  const toggleMulti = (val: string) => {
    const current = (answers[currentIdx] as string[]) ?? []
    setAnswer(current.includes(val) ? current.filter(v => v !== val) : [...current, val])
  }

  const submitQuestion = async () => {
    setGrading(true)
    const answer = answers[currentIdx] ?? (question.type === 'multiple' ? [] : '')

    try {
      const res = await fetch(`/learn/classroom/${sessionId}/quiz/${sceneId}`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name=csrf-token]')?.content ?? '',
        },
        body: JSON.stringify({ question_index: currentIdx, answer }),
      })
      const data = await res.json()
      const result: QuizResult = {
        questionIndex: currentIdx,
        isCorrect:     data.is_correct,
        score:         data.score,
        maxScore:      data.max_score,
        feedback:      data.feedback,
        analysis:      data.analysis,
      }
      const newResults = [...results, result]
      setResults(newResults)

      if (currentIdx < questions.length - 1) {
        setTimeout(() => {
          setCurrentIdx(i => i + 1)
          setGrading(false)
        }, 1800)
      } else {
        setGrading(false)
        setPhase('results')
        onComplete?.(newResults)
      }
    } catch {
      setGrading(false)
    }
  }

  if (phase === 'intro') {
    return (
      <div className="flex flex-col items-center justify-center h-full gap-6 p-8">
        <div className="text-5xl">📝</div>
        <h2 className="text-xl font-semibold text-gray-800">Knowledge Check</h2>
        <p className="text-gray-500 text-sm text-center">
          {questions.length} questions · {totalPts} points total
        </p>
        <button
          onClick={() => setPhase('answering')}
          className="bg-blue-600 text-white px-8 py-3 rounded-lg font-medium hover:bg-blue-700 transition-colors"
        >
          Start Quiz
        </button>
      </div>
    )
  }

  if (phase === 'results') {
    const earned = results.reduce((s, r) => s + r.score, 0)
    const pct    = Math.round((earned / totalPts) * 100)

    return (
      <div className="p-6 space-y-4 overflow-y-auto h-full">
        <div className="text-center py-4">
          <div className="text-4xl font-bold text-blue-600">{pct}%</div>
          <div className="text-sm text-gray-500 mt-1">{earned} / {totalPts} points</div>
        </div>
        {results.map((r, i) => (
          <div key={i} className={`border rounded-lg p-4 ${r.isCorrect ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50'}`}>
            <div className="flex items-start gap-2">
              <span className="text-lg">{r.isCorrect ? '✅' : '❌'}</span>
              <div className="flex-1">
                <p className="text-sm font-medium text-gray-800">{questions[i]?.question}</p>
                {r.feedback && <p className="text-xs text-gray-600 mt-1">{r.feedback}</p>}
                {r.analysis && <p className="text-xs text-blue-700 mt-1 italic">{r.analysis}</p>}
                <p className="text-xs text-gray-400 mt-1">{r.score} / {r.maxScore} pts</p>
              </div>
            </div>
          </div>
        ))}
      </div>
    )
  }

  // Answering phase
  const currentResult = results.find(r => r.questionIndex === currentIdx)
  const hasAnswer = answers[currentIdx] !== undefined &&
    (Array.isArray(answers[currentIdx]) ? (answers[currentIdx] as string[]).length > 0 : (answers[currentIdx] as string).trim() !== '')

  return (
    <div className="p-6 space-y-4 h-full flex flex-col">
      {/* Progress */}
      <div className="flex items-center gap-3">
        <div className="flex-1 bg-gray-200 rounded-full h-1.5">
          <div className="bg-blue-500 h-1.5 rounded-full transition-all"
            style={{ width: `${((currentIdx) / questions.length) * 100}%` }} />
        </div>
        <span className="text-xs text-gray-500 whitespace-nowrap">{currentIdx + 1} / {questions.length}</span>
      </div>

      {/* Question */}
      <div className="flex-1 space-y-4">
        <div className="flex items-start gap-2">
          <span className="bg-blue-100 text-blue-700 text-xs font-medium px-2 py-0.5 rounded-full whitespace-nowrap mt-0.5">
            {question.points ?? 10} pts
          </span>
          <p className="text-gray-800 font-medium">{question.question}</p>
        </div>

        {/* Single choice */}
        {question.type === 'single' && question.options && (
          <div className="space-y-2">
            {question.options.map(opt => {
              const selected = answers[currentIdx] === opt.value
              const isRight  = currentResult && opt.value === question.answer?.[0]
              const isWrong  = currentResult && selected && !currentResult.isCorrect

              return (
                <button key={opt.value} onClick={() => !currentResult && setAnswer(opt.value)}
                  className={`w-full text-left flex items-center gap-3 p-3 rounded-lg border transition-colors text-sm
                    ${isRight   ? 'border-green-400 bg-green-50' :
                      isWrong   ? 'border-red-400 bg-red-50' :
                      selected  ? 'border-blue-400 bg-blue-50' :
                      'border-gray-200 hover:border-gray-300'}`}>
                  <span className={`w-6 h-6 rounded-full border flex items-center justify-center text-xs font-medium flex-shrink-0
                    ${selected ? 'bg-blue-500 border-blue-500 text-white' : 'border-gray-300'}`}>
                    {opt.value}
                  </span>
                  {opt.label}
                </button>
              )
            })}
          </div>
        )}

        {/* Multiple choice */}
        {question.type === 'multiple' && question.options && (
          <div className="space-y-2">
            <p className="text-xs text-gray-400">Select all that apply</p>
            {question.options.map(opt => {
              const selected = ((answers[currentIdx] as string[]) ?? []).includes(opt.value)
              return (
                <button key={opt.value} onClick={() => !currentResult && toggleMulti(opt.value)}
                  className={`w-full text-left flex items-center gap-3 p-3 rounded-lg border text-sm transition-colors
                    ${selected ? 'border-blue-400 bg-blue-50' : 'border-gray-200 hover:border-gray-300'}`}>
                  <span className={`w-5 h-5 rounded border flex items-center justify-center flex-shrink-0
                    ${selected ? 'bg-blue-500 border-blue-500' : 'border-gray-300'}`}>
                    {selected && <span className="text-white text-xs">✓</span>}
                  </span>
                  {opt.label}
                </button>
              )
            })}
          </div>
        )}

        {/* Short answer */}
        {question.type === 'short_answer' && (
          <textarea
            className="w-full border rounded-lg p-3 text-sm resize-none h-28 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            placeholder="Type your answer here..."
            value={(answers[currentIdx] as string) ?? ''}
            onChange={e => setAnswer(e.target.value)}
            disabled={!!currentResult}
          />
        )}

        {/* Grading feedback */}
        {currentResult && (
          <div className={`border rounded-lg p-3 text-sm ${currentResult.isCorrect ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50'}`}>
            <p className="font-medium mb-1">{currentResult.isCorrect ? '✅ Correct!' : '❌ Not quite'}</p>
            {currentResult.feedback && <p className="text-gray-700">{currentResult.feedback}</p>}
            {currentResult.analysis && <p className="text-gray-600 italic mt-1 text-xs">{currentResult.analysis}</p>}
          </div>
        )}
      </div>

      {/* Submit */}
      {!currentResult && (
        <button
          onClick={submitQuestion}
          disabled={!hasAnswer || grading}
          className="w-full bg-blue-600 text-white py-3 rounded-lg font-medium hover:bg-blue-700 disabled:opacity-50 transition-colors"
        >
          {grading ? 'Grading...' : 'Submit Answer'}
        </button>
      )}
    </div>
  )
}
```

---

## 7 — ClassroomContent component

Create `resources/js/components/classroom/ClassroomContent.tsx` that routes
between scene types and handles the message chat area:

```tsx
import SlideRenderer from './SlideRenderer'
import QuizWidget from './QuizWidget'
import AgentChatBubble from './AgentChatBubble'

interface ClassroomContentProps {
  scene: any
  messages: any[]
  spotlightId: string | null
  laserTarget: string | null
  session: any
  onQuizSubmit: (sceneId: string, questionIndex: number, answer: any) => Promise<any>
}

export default function ClassroomContent({
  scene, messages, spotlightId, laserTarget, session, onQuizSubmit
}: ClassroomContentProps) {
  return (
    <div className="flex-1 flex flex-col min-h-0">
      {/* Scene content */}
      <div className="flex-shrink-0 border-b bg-white">
        {scene?.scene_type === 'slide' && (
          <div className="p-4">
            <SlideRenderer
              slide={scene.content}
              spotlightId={spotlightId}
              laserTarget={laserTarget}
            />
          </div>
        )}
        {scene?.scene_type === 'quiz' && (
          <div className="h-64 overflow-hidden">
            <QuizWidget
              questions={scene.content?.questions ?? []}
              sceneId={scene.id}
              sessionId={session.id}
            />
          </div>
        )}
        {scene?.scene_type === 'interactive' && (
          <div className="h-64">
            <iframe
              srcDoc={scene.content?.html ?? ''}
              className="w-full h-full border-0"
              sandbox="allow-scripts"
              title="Interactive simulation"
            />
          </div>
        )}
        {scene?.scene_type === 'discussion' && (
          <div className="p-4 bg-amber-50 border-b">
            <p className="text-sm font-medium text-amber-800">💬 {scene.content?.topic ?? 'Discussion'}</p>
            {scene.content?.prompt && (
              <p className="text-xs text-amber-600 mt-1">{scene.content.prompt}</p>
            )}
          </div>
        )}
      </div>

      {/* Chat messages */}
      <div className="flex-1 overflow-y-auto p-4 space-y-3 min-h-0">
        {messages.map((msg: any) => (
          msg.role === 'agent'
            ? <AgentChatBubble key={msg.id} message={msg} />
            : <StudentChatBubble key={msg.id} message={msg} />
        ))}
      </div>
    </div>
  )
}

function StudentChatBubble({ message }: { message: any }) {
  return (
    <div className="flex justify-end">
      <div className="bg-amber-50 border border-amber-200 rounded-2xl rounded-tr-sm px-4 py-2 max-w-[75%]">
        <p className="text-sm text-amber-900">{message.content}</p>
      </div>
    </div>
  )
}

function AgentChatBubble({ message }: { message: any }) {
  return (
    <div className="flex items-start gap-2">
      <div className="w-8 h-8 rounded-full flex items-center justify-center text-sm flex-shrink-0"
        style={{ backgroundColor: (message.agentColor ?? '#1E3A5F') + '20', border: `2px solid ${message.agentColor ?? '#1E3A5F'}` }}>
        {message.agentEmoji ?? '🤖'}
      </div>
      <div className="min-w-0 flex-1">
        <p className="text-xs font-medium mb-1" style={{ color: message.agentColor ?? '#1E3A5F' }}>
          {message.agentName}
        </p>
        <div className="bg-white border rounded-2xl rounded-tl-sm px-4 py-2 inline-block max-w-full">
          <p className="text-sm text-gray-800 whitespace-pre-wrap">{message.content}</p>
        </div>
      </div>
    </div>
  )
}
```

---

## 8 — Discussion action: server-side handling

When the agent emits a `discussion` action, the server should automatically
start a multi-agent discussion round. Update the SSE message handler in
`ClassroomController::message()` to detect discussion actions:

```php
// In the SSE loop, after processing each event:
if ($event['type'] === 'action' && ($event['data']['actionName'] ?? '') === 'discussion') {
    // Store discussion context in session and trigger next agent round
    $discussionData = $event['data']['params'] ?? [];
    $session->update([
        'session_type' => 'discussion',
        'director_state' => array_merge($session->director_state ?? [], [
            'discussion_topic'  => $discussionData['topic'] ?? '',
            'discussion_prompt' => $discussionData['prompt'] ?? '',
        ]),
    ]);
    // Trigger agent-initiated discussion in next iteration
    $studentMessage = ''; // Empty — agents initiate this round
}
```

---

## 9 — WhiteboardCanvas: chart and table rendering

The basic `WhiteboardCanvas` uses placeholders for charts and tables.
For production, add recharts (already in `package.json`) for charts:

Update `WhiteboardElement` in `WhiteboardCanvas.tsx`:

```tsx
// Add at top of file:
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, LineChart, Line, PieChart, Pie } from 'recharts'

// In WhiteboardElement, add:
if (element.type === 'chart') {
  const data  = element.data ?? {}
  const items = (data.labels ?? []).map((label: string, i: number) => ({
    name: label,
    ...Object.fromEntries((data.legends ?? []).map((leg: string, j: number) => [leg, (data.series?.[j] ?? [])[i] ?? 0]))
  }))
  const colors = ['#5b9bd5','#ed7d31','#a9d18e','#ffc000']

  return (
    <div style={baseStyle}>
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={items}>
          <CartesianGrid strokeDasharray="3 3" />
          <XAxis dataKey="name" tick={{ fontSize: 10 }} />
          <YAxis tick={{ fontSize: 10 }} />
          {(data.legends ?? []).map((leg: string, i: number) => (
            <Bar key={leg} dataKey={leg} fill={colors[i % colors.length]} />
          ))}
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}

if (element.type === 'table') {
  return (
    <div style={baseStyle} className="overflow-hidden">
      <table className="w-full h-full border-collapse" style={{ fontSize: '10px' }}>
        {(element.data ?? []).map((row: any[], ri: number) => (
          <tr key={ri} className={ri === 0 ? 'bg-gray-100 font-bold' : ''}>
            {row.map((cell: any, ci: number) => (
              <td key={ci} className="border border-gray-300 px-1 py-0.5 text-center">{cell}</td>
            ))}
          </tr>
        ))}
      </table>
    </div>
  )
}
```

---

## 10 — LLM streaming in PHP (LLMService update)

The `AgentOrchestrator` calls `$this->llm->stream()` which must return a
generator of string chunks. Ensure `LLMService` has this method:

```php
// In app/Services/AI/LLMService.php — add stream method:

/**
 * Stream LLM response as a generator of string chunks.
 * @return \Generator<string>
 */
public function stream(string $systemPrompt, array $messages, int $maxTokens = 2000): \Generator
{
    $payload = [
        'model'      => config('openai.model'),
        'max_tokens' => $maxTokens,
        'stream'     => true,
        'messages'   => array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $messages,
        ),
    ];

    $client = \Illuminate\Support\Facades\Http::withHeaders([
        'Authorization' => 'Bearer ' . config('openai.api_key'),
        'Content-Type'  => 'application/json',
    ])->withOptions(['stream' => true]);

    $response = $client->post(config('openai.base_url') . '/chat/completions', $payload);

    $buffer = '';
    foreach ($response->toPsrResponse()->getBody() as $chunk) {
        $buffer .= $chunk;
        $lines   = explode("\n", $buffer);
        $buffer  = array_pop($lines); // Keep incomplete last line

        foreach ($lines as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'data: ')) continue;
            $data = substr($line, 6);
            if ($data === '[DONE]') return;

            $parsed = json_decode($data, true);
            $delta  = $parsed['choices'][0]['delta']['content'] ?? '';
            if ($delta !== '') yield $delta;
        }
    }
}
```

---

## 11 — Final acceptance additions

Add to the Phase 3d acceptance checklist:

- [ ] Slide renderer displays all 6 element types (text, shape, latex, chart, table, line)
- [ ] Spotlight overlay dims background and highlights correct element
- [ ] Laser pointer shows red outline on targeted element
- [ ] Quiz intro → answering → per-question grading → results flow works
- [ ] Short answer questions call the server grade endpoint
- [ ] Multiple choice grading is deterministic (no LLM call)
- [ ] Whiteboard chart elements render using recharts
- [ ] Discussion action triggers a second agent round automatically
- [ ] KaTeX renders LaTeX formulas in both slides and whiteboard
- [ ] LLM streaming (stream: true) works with Ollama, OpenAI, and vLLM
- [ ] Interactive simulation iframe has `sandbox="allow-scripts"` ONLY (no allow-same-origin)
- [ ] Generated HTML containing fetch() is rejected and scene falls back to slide type
- [ ] Cross-scene continuity: first scene greets, middle scenes continue, last scene closes

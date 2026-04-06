<?php

namespace App\Services\Classroom;

use App\Models\ClassroomLesson;
use App\Models\LessonAgent;
use App\Models\LessonScene;
use App\Services\AI\ChatCompletionClient;
use Illuminate\Support\Str;

class LessonGeneratorService
{
    public function __construct(
        private ChatCompletionClient $llm,
        private InteractiveHtmlProcessor $htmlProcessor,
    ) {}

    public function generateOutline(ClassroomLesson $lesson): void
    {
        $lesson->update(['generation_status' => 'generating_outline']);

        $systemPrompt = $this->outlineSystemPrompt($lesson);
        $userPrompt = $this->outlineUserPrompt($lesson);

        $response = $this->llm->complete($systemPrompt, $userPrompt);
        $outlines = $this->parseJsonArray($response);

        if ($outlines === []) {
            $trimmed = trim($response);
            $lesson->update([
                'generation_status' => 'failed',
                'generation_progress' => [
                    'message' => $trimmed === ''
                        ? 'No outline returned (often: missing/invalid OPENAI_API_KEY, API error, or quota). Check storage/logs and queue workers.'
                        : 'Could not parse outline JSON from the model.',
                    'response_preview' => $trimmed !== '' ? mb_substr($trimmed, 0, 500) : null,
                    'hint' => 'Confirm OPENAI_API_KEY in .env, run `php artisan config:clear`, and ensure `php artisan queue:work` or Horizon is processing the default queue.',
                ],
            ]);

            return;
        }

        foreach ($outlines as $i => &$outline) {
            $outline['id'] = $outline['id'] ?? (string) Str::uuid();
            $outline['order'] = $i + 1;
        }
        unset($outline);

        $lesson->update([
            'outline' => $outlines,
            'generation_status' => 'generating_scenes',
            'generation_progress' => [
                'step' => 'generating_scenes',
                'progress' => 30,
                'total_scenes' => count($outlines),
                'scenes_generated' => 0,
            ],
        ]);

        foreach ($outlines as $outline) {
            LessonScene::create([
                'lesson_id' => $lesson->id,
                'district_id' => $lesson->district_id,
                'sequence_order' => $outline['order'],
                'scene_type' => $outline['type'] ?? 'slide',
                'title' => $outline['title'] ?? 'Scene',
                'learning_objective' => $outline['teachingObjective'] ?? null,
                'estimated_duration_seconds' => $outline['estimatedDuration'] ?? 120,
                'outline_data' => $outline,
                'generation_status' => 'pending',
            ]);
        }
    }

    private function outlineSystemPrompt(ClassroomLesson $lesson): string
    {
        $gradeLevel = $lesson->grade_level ?? 'general';
        $language = $lesson->language === 'es' ? 'Spanish' : 'English';

        return <<<PROMPT
You are a K-12 curriculum designer. Analyze the user's requirement and generate a structured lesson outline.

## Scene types available
- slide: Visual presentation with text, images, diagrams
- quiz: Assessment with multiple choice or short answer questions
- interactive: Self-contained HTML simulation (physics, math visualization, etc.) — LIMIT to 1-2 per lesson
- discussion: Structured agent discussion on a topic

## Design rules
- Target grade level: {$gradeLevel}
- Language: ALL content must be in {$language}
- Scene count: 4-8 scenes total (aim for 15-20 minutes at 2-3 min per scene)
- Sequence: Hook → Instruction → Practice → Quiz → Summary
- Insert a quiz every 3-5 slides
- Interactive scenes: only for concepts that genuinely benefit from simulation
- Interactive scenes must NOT use any external CDN links — inline CSS and pure JS only
- All content must be age-appropriate for K-12 public school

## ATLAAS safety constraints
- No violence, self-harm, adult content, or political bias
- No external web resources or links in interactive scenes
- Content must align with standard K-12 curriculum

## Output format
Output a JSON array only. No explanation, no code fences.
Each element:
{
  "id": "scene_N",
  "type": "slide|quiz|interactive|discussion",
  "title": "string",
  "description": "1-2 sentences on teaching purpose",
  "keyPoints": ["point 1", "point 2", "point 3"],
  "teachingObjective": "string",
  "estimatedDuration": 120,
  "order": N,
  "quizConfig": {"questionCount":3,"difficulty":"easy|medium|hard","questionTypes":["single","multiple","short_answer"]},
  "interactiveConfig": {"conceptName":"...","conceptOverview":"...","designIdea":"...","subject":"..."}
}
quizConfig required for quiz scenes. interactiveConfig required for interactive scenes.
PROMPT;
    }

    private function outlineUserPrompt(ClassroomLesson $lesson): string
    {
        $source = $lesson->source_text ?? '';
        $grade = $lesson->grade_level ? "Grade level: {$lesson->grade_level}" : '';
        $subj = $lesson->subject ? "Subject: {$lesson->subject}" : '';

        return "Generate a lesson outline for:\n\n{$source}\n\n{$grade}\n{$subj}\n\nOutput JSON array only.";
    }

    public function generateSceneContent(LessonScene $scene): void
    {
        $scene->update(['generation_status' => 'generating']);

        try {
            $content = match ($scene->scene_type) {
                'slide' => $this->generateSlideContent($scene),
                'quiz' => $this->generateQuizContent($scene),
                'interactive' => $this->generateInteractiveContent($scene),
                'discussion' => $this->generateDiscussionContent($scene),
                default => $this->generateSlideContent($scene),
            };

            if ($content === null) {
                if ($scene->scene_type === 'interactive') {
                    $scene->update(['scene_type' => 'slide']);
                    $scene->refresh();
                    $content = $this->generateSlideContent($scene);
                }
            }

            $actions = $this->generateActionSequence($scene, $content ?? []);

            $scene->update([
                'content' => $content,
                'actions' => $actions,
                'generation_status' => 'ready',
            ]);
        } catch (\Throwable $e) {
            $scene->update([
                'generation_status' => 'error',
                'generation_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return ?array<string, mixed>
     */
    private function generateSlideContent(LessonScene $scene): ?array
    {
        $outline = $scene->outline_data ?? [];
        $systemPrompt = $this->slideContentSystemPrompt();
        $userPrompt = $this->slideContentUserPrompt($scene, $outline);

        $response = $this->llm->complete($systemPrompt, $userPrompt);
        $parsed = $this->parseJsonObject($response);

        return [
            'type' => 'slide',
            'elements' => $parsed['elements'] ?? [],
            'background' => $parsed['background'] ?? ['type' => 'solid', 'color' => '#ffffff'],
        ];
    }

    /**
     * Phase3d_Addendum §2 — precise slide element schemas for the LLM.
     */
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
  CRITICAL: Never put LaTeX (\frac, \int, x^2) in content — use LatexElement instead.

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

    /**
     * @param  array<string, mixed>  $outline
     */
    private function slideContentUserPrompt(LessonScene $scene, array $outline): string
    {
        $lesson = $scene->lesson;
        $allScenes = $lesson->scenes()->orderBy('sequence_order')->get();
        $position = $allScenes->search(fn ($s) => $s->id === $scene->id) + 1;
        $total = $allScenes->count();
        $allTitles = $allScenes->pluck('title')->implode(', ');

        $posNote = match (true) {
            $position === 1 => 'FIRST scene: open with greeting and introduction.',
            $position === $total => 'LAST scene: summarize and close the lesson.',
            default => "Scene {$position} of {$total}: continue naturally, do NOT re-greet.",
        };

        $keyPoints = implode("\n- ", $outline['keyPoints'] ?? []);
        $description = (string) ($outline['description'] ?? '');

        return <<<TEXT
Title: {$scene->title}
Description: {$description}
Key points:
- {$keyPoints}

Position: {$posNote}
All scene titles: {$allTitles}

Generate slide elements JSON only.
TEXT;
    }

    /**
     * @return array<string, mixed>
     */
    private function generateQuizContent(LessonScene $scene): array
    {
        $outline = $scene->outline_data ?? [];
        $quizConfig = $outline['quizConfig'] ?? ['questionCount' => 3, 'difficulty' => 'medium', 'questionTypes' => ['single']];
        $count = (int) ($quizConfig['questionCount'] ?? 3);
        $difficulty = $quizConfig['difficulty'] ?? 'medium';
        $types = implode(', ', $quizConfig['questionTypes'] ?? ['single']);

        $systemPrompt = <<<PROMPT
You are a K-12 educational assessment designer.
Generate quiz questions as a JSON array. Output JSON only, no code fences.

Each question:
{
  "id": "q1",
  "type": "single|multiple|short_answer",
  "question": "...",
  "options": [{"label":"Option A text","value":"A"},{"label":"Option B text","value":"B"}],
  "answer": ["A"],
  "analysis": "Explanation of the correct answer",
  "points": 10
}
- options and answer required for single/multiple type
- short_answer: omit options, set answer to null, include "commentPrompt" grading rubric
- Every question must include "analysis"
- Difficulty: {$difficulty}
- Age-appropriate for K-12, no controversial content
PROMPT;

        $userPrompt = "Topic: {$scene->title}\nGenerate {$count} questions using types: {$types}\nKey points covered: ".implode(', ', $outline['keyPoints'] ?? [])."\n\nOutput JSON array only.";

        $response = $this->llm->complete($systemPrompt, $userPrompt);
        $questions = $this->parseJsonArray($response);

        return ['type' => 'quiz', 'questions' => $questions];
    }

    /**
     * @return ?array<string, mixed>
     */
    private function generateInteractiveContent(LessonScene $scene): ?array
    {
        $outline = $scene->outline_data ?? [];
        $config = $outline['interactiveConfig'] ?? null;
        if (! $config) {
            return null;
        }

        $modelSystemPrompt = <<<'PROMPT'
You are a science education expert. Produce a scientific model for the concept.
Output JSON only:
{"core_formulas":["..."],"mechanism":["..."],"constraints":["..."],"forbidden_errors":["..."]}
2-5 items per array. Be specific. No extra text.
PROMPT;

        $conceptName = $config['conceptName'] ?? '';
        $modelUserPrompt = "Concept: {$conceptName}\nSubject: ".($config['subject'] ?? '')."\nOverview: ".($config['conceptOverview'] ?? '');
        $modelResponse = $this->llm->complete($modelSystemPrompt, $modelUserPrompt);
        $scientificModel = $this->parseJsonObject($modelResponse);

        $constraints = '';
        if ($scientificModel !== []) {
            $lines = [];
            if (! empty($scientificModel['core_formulas'])) {
                $lines[] = 'Formulas: '.implode('; ', $scientificModel['core_formulas']);
            }
            if (! empty($scientificModel['mechanism'])) {
                $lines[] = 'Mechanisms: '.implode('; ', $scientificModel['mechanism']);
            }
            if (! empty($scientificModel['constraints'])) {
                $lines[] = 'Must obey: '.implode('; ', $scientificModel['constraints']);
            }
            if (! empty($scientificModel['forbidden_errors'])) {
                $lines[] = 'Never do: '.implode('; ', $scientificModel['forbidden_errors']);
            }
            $constraints = implode("\n", $lines);
        }

        $htmlSystemPrompt = <<<'PROMPT'
You are an educational web developer for K-12 classrooms.
Create a self-contained interactive HTML5 page for a concept.

CRITICAL CONSTRAINTS:
- NO external script src attributes (no CDN JS libraries)
- NO external link href attributes (no CDN CSS)
- Use ONLY inline styles and embedded <style> tags
- Pure JavaScript only (no frameworks)
- Canvas API or SVG for visualizations
- All simulations must strictly follow the scientific constraints provided
- All UI text must be in English
- The page will run in a sandboxed iframe with no network access

Output the complete HTML document directly. No code fences, no explanation.
PROMPT;

        $keyPoints = implode("\n", $outline['keyPoints'] ?? []);
        $htmlUserPrompt = "Concept: {$conceptName}\nSubject: ".($config['subject'] ?? '')."\nOverview: ".($config['conceptOverview'] ?? '')."\nKey points:\n{$keyPoints}\n\nScientific constraints:\n{$constraints}\n\nDesign idea: ".($config['designIdea'] ?? '')."\n\nOutput complete HTML only.";

        $htmlResponse = $this->llm->complete($htmlSystemPrompt, $htmlUserPrompt);
        $rawHtml = $this->extractHtml($htmlResponse);
        if (! $rawHtml) {
            return null;
        }

        $processedHtml = $this->htmlProcessor->process($rawHtml);
        if (! $processedHtml) {
            return null;
        }

        return ['type' => 'interactive', 'html' => $processedHtml];
    }

    /**
     * @return array<string, mixed>
     */
    private function generateDiscussionContent(LessonScene $scene): array
    {
        $outline = $scene->outline_data ?? [];

        return [
            'type' => 'discussion',
            'topic' => $scene->title,
            'prompt' => implode(' ', array_slice($outline['keyPoints'] ?? [], 0, 2)),
            'duration_seconds' => $scene->estimated_duration_seconds ?? 180,
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return list<array<string, mixed>>
     */
    private function generateActionSequence(LessonScene $scene, array $content): array
    {
        if ($scene->scene_type === 'interactive') {
            return [];
        }

        $lesson = $scene->lesson;
        $agents = $lesson->agents()->where('is_active', true)->get();
        $teacher = $agents->firstWhere('role', 'teacher');

        if (! $teacher) {
            return [];
        }

        $systemPrompt = $this->actionSequenceSystemPrompt($scene->scene_type, $teacher);
        $userPrompt = $this->actionSequenceUserPrompt($scene, $content);

        $response = $this->llm->complete($systemPrompt, $userPrompt, 2000);

        return $this->parseActionArray($response, $scene->scene_type);
    }

    private function actionSequenceSystemPrompt(string $sceneType, LessonAgent $teacher): string
    {
        $slideActions = $sceneType === 'slide'
            ? "- spotlight: Focus on a slide element. params: {elementId:string}\n- laser: Point at a slide element. params: {elementId:string}\n- play_video: Play embedded video. params: {elementId:string}\n"
            : '';

        return <<<PROMPT
You are generating a teaching action sequence for a classroom agent ({$teacher->display_name}).

## Output format
JSON array only. Each item is:
{"type":"text","content":"spoken words"} or {"type":"action","name":"action_name","params":{...}}

## Available actions
{$slideActions}- wb_open: Open whiteboard. params: {}
- wb_draw_text: Add text. params: {content, x, y, width?, height?, fontSize?, color?, elementId?}
- wb_draw_shape: Add shape. params: {shape:"rectangle|circle|triangle", x, y, width, height, fillColor?, elementId?}
- wb_draw_latex: Add formula. params: {latex, x, y, height?, color?, elementId?}
- wb_draw_line: Add line/arrow. params: {startX, startY, endX, endY, color?, style?, points?}
- wb_clear: Clear whiteboard. params: {}
- discussion: Trigger discussion. params: {topic, prompt?}

## Whiteboard canvas: 1000 × 562. All coordinates within bounds.

## Speech guidelines (CRITICAL)
- Text content is what the teacher SAYS OUT LOUD — conversational, not prose
- Keep total speech to ~100 characters across all text items
- Never announce actions ("let me draw...") — just teach naturally
- spotlight before the text that references it
- Whiteboard draws can interleave with speech

## K-12 safety
- Age-appropriate language for K-12 students
- No inappropriate content

Output 5-10 items. JSON array only, no explanation.
PROMPT;
    }

    /**
     * Phase3d_Addendum §4 — cross-scene continuity.
     *
     * @param  array<string, mixed>  $content
     */
    private function actionSequenceUserPrompt(LessonScene $scene, array $content): string
    {
        $lesson = $scene->lesson;
        $allScenes = $lesson->scenes()->orderBy('sequence_order')->get();
        $position = $allScenes->search(fn ($s) => $s->id === $scene->id) + 1;
        $total = $allScenes->count();
        $outline = $scene->outline_data ?? [];
        $keyPoints = implode("\n- ", $outline['keyPoints'] ?? []);

        $posNote = match (true) {
            $position === 1 => 'FIRST scene — greet students and introduce the lesson.',
            $position === $total => 'LAST scene — summarize the whole lesson and close warmly.',
            default => "Scene {$position} of {$total} — continue naturally, NO greeting.",
        };

        $continuityNote = "All scenes are one continuous class session. Never say 'last class' or 'previous session'.";

        if ($scene->scene_type === 'slide') {
            $elementSummary = collect($content['elements'] ?? [])
                ->map(function ($el) {
                    $snippet = strip_tags((string) ($el['content'] ?? ''));
                    if ($snippet === '' && isset($el['latex'])) {
                        $snippet = (string) $el['latex'];
                    }

                    return "[id:{$el['id']}] {$el['type']}: ".mb_substr($snippet, 0, 50);
                })
                ->implode("\n");

            return "Title: {$scene->title}\nKey points:\n- {$keyPoints}\n\nSlide elements:\n{$elementSummary}\n\n{$posNote}\n{$continuityNote}\n\nGenerate action sequence JSON array (5-10 items).";
        }

        if ($scene->scene_type === 'quiz') {
            $questions = collect($content['questions'] ?? [])
                ->map(fn ($q, $i) => ($i + 1).". [{$q['type']}] {$q['question']}")
                ->implode("\n");

            return "Quiz: {$scene->title}\nQuestions:\n{$questions}\n\n{$posNote}\n{$continuityNote}\n\nGenerate brief intro speech + one discussion action at the end. JSON array only.";
        }

        return "Scene: {$scene->title}\nKey points:\n- {$keyPoints}\n\n{$posNote}\n{$continuityNote}\n\nGenerate action sequence JSON array.";
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseJsonArray(string $response): array
    {
        $response = trim($response);
        $response = preg_replace('/^```(?:json)?\s*/i', '', $response) ?? $response;
        $response = preg_replace('/\s*```\s*$/i', '', $response) ?? $response;

        $start = strpos($response, '[');
        $end = strrpos($response, ']');
        if ($start === false || $end === false) {
            return [];
        }

        $json = substr($response, $start, $end - $start + 1);
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonObject(string $response): array
    {
        $response = trim($response);
        $response = preg_replace('/^```(?:json)?\s*/i', '', $response) ?? $response;
        $response = preg_replace('/\s*```\s*$/i', '', $response) ?? $response;

        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start === false || $end === false) {
            return [];
        }

        $json = substr($response, $start, $end - $start + 1);
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseActionArray(string $response, string $sceneType): array
    {
        $items = $this->parseJsonArray($response);
        $actions = [];
        $slideOnly = ['spotlight', 'laser', 'play_video'];

        foreach ($items as $item) {
            if (! isset($item['type'])) {
                continue;
            }

            if ($item['type'] === 'text') {
                $actions[] = [
                    'id' => 'act_'.Str::random(8),
                    'type' => 'speech',
                    'text' => $item['content'] ?? '',
                ];
            } elseif ($item['type'] === 'action') {
                $name = $item['name'] ?? '';
                if ($sceneType !== 'slide' && in_array($name, $slideOnly, true)) {
                    continue;
                }
                $actions[] = array_merge(
                    ['id' => 'act_'.Str::random(8), 'type' => $name],
                    is_array($item['params'] ?? null) ? $item['params'] : []
                );
            }
        }

        return $actions;
    }

    private function extractHtml(string $response): ?string
    {
        if (preg_match('/```html\s*([\s\S]+?)\s*```/i', $response, $m)) {
            return trim($m[1]);
        }
        $pos = stripos($response, '<!DOCTYPE html');
        if ($pos !== false) {
            return substr($response, $pos);
        }
        $pos = stripos($response, '<html');
        if ($pos !== false) {
            return substr($response, $pos);
        }

        return null;
    }
}

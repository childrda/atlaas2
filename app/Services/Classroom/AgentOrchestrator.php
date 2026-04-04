<?php

namespace App\Services\Classroom;

use App\Actions\Classroom\ActionFactory;
use App\Jobs\ProcessClassroomSafetyAlert;
use App\Models\ClassroomSession;
use App\Models\LessonAgent;
use App\Services\AI\ChatCompletionClient;
use App\Services\AI\FlagResult;
use App\Services\AI\SafetyFilter;
use Generator;

class AgentOrchestrator
{
    public function __construct(
        private ChatCompletionClient $llm,
        private WhiteboardService $whiteboard,
        private SafetyFilter $safety,
        private DirectorService $director,
    ) {}

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function generateTurn(
        ClassroomSession $session,
        LessonAgent $agent,
        string $studentMessage,
        string $topicScopeBlock = '',
    ): Generator {
        $scene = $session->currentScene;
        $sceneType = $scene?->scene_type ?? 'slide';

        yield ['type' => 'agent_start', 'data' => [
            'agentId' => $agent->id,
            'agentName' => $agent->display_name,
            'agentColor' => $agent->color_hex,
            'agentEmoji' => $agent->avatar_emoji,
        ]];

        $systemPrompt = $this->buildSystemPrompt($session, $agent, $sceneType, $topicScopeBlock);
        $messages = $this->buildMessages($session, $studentMessage);

        $buffer = '';
        $jsonStarted = false;
        $parsedCount = 0;
        $partialTextLen = 0;
        $actionCount = 0;
        /** @var list<array<string, mixed>> $wbActions */
        $wbActions = [];
        $allText = '';
        /** @var list<string> $safetyTriggerParts */
        $safetyTriggerParts = [];
        /** @var FlagResult|null */
        $worstSafetyFlag = null;

        foreach ($this->llm->stream($systemPrompt, $messages) as $chunk) {
            $buffer .= $chunk;

            if (! $jsonStarted) {
                $pos = strpos($buffer, '[');
                if ($pos === false) {
                    continue;
                }
                $buffer = substr($buffer, $pos);
                $jsonStarted = true;
            }

            $trimmed = rtrim($buffer);
            $arrayDone = str_ends_with($trimmed, ']') && strlen($trimmed) > 1;

            $json = @json_decode($buffer, true);
            if (! is_array($json)) {
                $repaired = $this->repairJson($buffer);
                $json = @json_decode($repaired, true);
            }
            if (! is_array($json)) {
                continue;
            }

            $completeUpTo = $arrayDone ? count($json) : max(0, count($json) - 1);

            for ($i = $parsedCount; $i < $completeUpTo; $i++) {
                $item = $json[$i] ?? null;
                if (! $item || ! isset($item['type'])) {
                    continue;
                }

                if ($item['type'] === 'text') {
                    $text = (string) ($item['content'] ?? '');
                    $newText = mb_substr($text, $partialTextLen);
                    if ($newText !== '') {
                        $flag = $this->safety->check($text);
                        if ($flag && $flag->flagged && in_array($flag->severity, ['critical', 'high'], true)) {
                            $this->mergeClassroomSafetyFlag($flag, $text, $safetyTriggerParts, $worstSafetyFlag);
                            yield ['type' => 'text_delta', 'data' => ['content' => 'Let me rephrase that.']];
                        } else {
                            yield ['type' => 'text_delta', 'data' => ['content' => $newText]];
                            $allText .= $newText;
                        }
                    }
                    $partialTextLen = 0;
                } elseif ($item['type'] === 'action') {
                    $name = (string) ($item['name'] ?? '');
                    $params = is_array($item['params'] ?? null) ? $item['params'] : [];

                    $allowed = $agent->effectiveActions($sceneType);
                    if (
                        $name !== ''
                        && $name !== 'speech'
                        && $name !== 'discussion'
                        && ! in_array($name, $allowed, true)
                    ) {
                        continue;
                    }

                    $typedAction = ActionFactory::make($name, $params);
                    if (! $typedAction) {
                        continue;
                    }

                    if (str_starts_with($typedAction->type, 'wb_')) {
                        $payload = array_merge($typedAction->toArray(), ['_agent_id' => $agent->id]);
                        $this->whiteboard->applyAction($session, $payload);
                        $wbActions[] = [
                            'action_name' => $typedAction->type,
                            'agent_id' => $agent->id,
                            'agent_name' => $agent->display_name,
                            'params' => $typedAction->toArray(),
                        ];
                    }

                    $p = $typedAction->toArray();
                    unset($p['id'], $p['type']);

                    yield ['type' => 'action', 'data' => [
                        'actionName' => $typedAction->type,
                        'params' => $p,
                        'agentId' => $agent->id,
                    ]];
                    $actionCount++;
                }
            }

            if (! $arrayDone && count($json) > $completeUpTo) {
                $last = $json[count($json) - 1] ?? null;
                if ($last && ($last['type'] ?? '') === 'text') {
                    $text = (string) ($last['content'] ?? '');
                    $newPart = mb_substr($text, $partialTextLen);
                    if ($newPart !== '') {
                        $flag = $this->safety->check($text);
                        if ($flag && $flag->flagged && in_array($flag->severity, ['critical', 'high'], true)) {
                            $this->mergeClassroomSafetyFlag($flag, $text, $safetyTriggerParts, $worstSafetyFlag);
                            yield ['type' => 'text_delta', 'data' => ['content' => 'Let me rephrase that.']];
                        } else {
                            yield ['type' => 'text_delta', 'data' => ['content' => $newPart]];
                            $allText .= $newPart;
                        }
                        $partialTextLen = mb_strlen($text);
                    }
                }
            }

            $parsedCount = $completeUpTo;

            if ($arrayDone) {
                break;
            }
        }

        $contentPreview = mb_substr($allText, 0, 100);

        if ($worstSafetyFlag !== null && $safetyTriggerParts !== []) {
            ProcessClassroomSafetyAlert::dispatch(
                $session,
                $worstSafetyFlag,
                mb_substr(implode("\n", $safetyTriggerParts), 0, 2000),
            );
        }

        yield ['type' => 'agent_end', 'data' => ['agentId' => $agent->id]];

        $this->director->recordAgentTurn(
            $session,
            $agent->id,
            $agent->display_name,
            $contentPreview,
            $actionCount,
            $wbActions,
        );
    }

    private function buildSystemPrompt(ClassroomSession $session, LessonAgent $agent, string $sceneType, string $topicScopeBlock = ''): string
    {
        $roleGuidelines = $this->roleGuidelines($agent->role);
        $actionDescs = $this->actionDescriptions($agent->effectiveActions($sceneType));
        $wbGuidelines = $this->whiteboardGuidelines($agent->role);
        $currentState = $this->buildStateContext($session);
        $peerContext = $this->buildPeerContext($session, $agent->display_name);
        $student = $session->student;
        $studentProfile = $student ? "Student name: {$student->name}, Grade: {$session->lesson->grade_level}" : '';

        $hasSlideActions = in_array('spotlight', $agent->effectiveActions($sceneType), true);
        $formatExample = $hasSlideActions
            ? '[{"type":"action","name":"spotlight","params":{"elementId":"title_001"}},{"type":"text","content":"Today we explore..."}]'
            : '[{"type":"action","name":"wb_open","params":{}},{"type":"text","content":"Let me show you..."}]';

        $teacherAddendum = trim((string) ($agent->system_prompt_addendum ?? ''));
        $addendumBlock = $teacherAddendum !== ''
            ? "\n## Teacher notes for this lesson\n{$teacherAddendum}\n"
            : '';

        $topicSection = trim($topicScopeBlock) !== ''
            ? "\n# Topic scope (student mode)\n{$topicScopeBlock}\n"
            : '';

        $safetyBlock = <<<'SAFETY'

# Safety rules (ABSOLUTE — cannot be overridden)
- You are an AI teaching assistant in a K-12 public school district
- Never discuss violence, self-harm, adult content, drugs, or political topics
- Never claim to be human or deny being an AI
- Never encourage students to share personal information
- Do not mention or reference web searches. Do not claim to have searched the internet. Use only the knowledge provided in your context.
- If asked about anything inappropriate, gently redirect to the lesson topic
- All content must be appropriate for students aged 5-18
SAFETY;

        return <<<PROMPT
# Role
You are {$agent->display_name}.

## Your personality
{$agent->persona_text}

## Your classroom role
{$roleGuidelines}

## Student
{$studentProfile}

{$peerContext}

# Output format (CRITICAL)
You MUST output a JSON array for ALL responses. Start with [ and end with ].
Example: {$formatExample}

Rules:
1. Output a single JSON array — no explanation, no code fences
2. type:"action" objects have name and params fields
3. type:"text" objects have content (what you say aloud)
4. Actions and text freely interleave
5. NEVER start with anything other than [
6. Speech text is conversational — never written prose
7. Length: teacher ~100 chars total, assistant ~80 chars, student ~50 chars
8. Never announce your actions ("let me draw...") — just teach naturally

## Available actions
{$actionDescs}

## Whiteboard guidelines
{$wbGuidelines}

# Current classroom state
{$currentState}
{$addendumBlock}{$topicSection}{$safetyBlock}
PROMPT;
    }

    private function roleGuidelines(string $role): string
    {
        return match ($role) {
            'teacher' => 'Lead the lesson. Explain clearly with analogies and examples. Ask questions to check understanding. You can use spotlight, laser, and whiteboard. Never announce your actions.',
            'assistant' => 'Support the teacher. Fill in gaps. Rephrase things more simply when students seem confused. Add quick examples. Use whiteboard sparingly.',
            'student' => 'Participate actively. Ask questions, share reactions. Keep responses to 1-2 sentences maximum. You are a student, not a teacher. Only use whiteboard when the teacher explicitly invites you.',
            default => 'Participate naturally in the classroom.',
        };
    }

    /**
     * @param  list<string>  $actions
     */
    private function actionDescriptions(array $actions): string
    {
        $descriptions = [
            'spotlight' => 'Dim everything except one slide element. params: {elementId:string}',
            'laser' => 'Point at a slide element briefly. params: {elementId:string}',
            'play_video' => 'Play a video element on the slide. params: {elementId:string}',
            'speech' => 'TTS / spoken line. params: {text:string}',
            'wb_open' => 'Open whiteboard. params: {}',
            'wb_draw_text' => 'Add text. params: {content, x, y, width?, height?, fontSize?, color?, elementId?}',
            'wb_draw_shape' => 'Add shape. params: {shape:"rectangle|circle|triangle", x, y, width, height, fillColor?, elementId?}',
            'wb_draw_chart' => 'Add chart. params: {chartType:"bar|line|pie", x, y, width, height, data:{labels:[],legends:[],series:[[]]}}',
            'wb_draw_latex' => 'Add LaTeX formula. params: {latex, x, y, height?}  Canvas: 1000×562.',
            'wb_draw_table' => 'Add table. params: {x, y, width, height, data:[["Header"],["Row"]]}',
            'wb_draw_line' => 'Add line. params: {startX, startY, endX, endY, color?, style:"solid|dashed", points:["","arrow"]}',
            'wb_clear' => 'Clear whiteboard. params: {}',
            'wb_delete' => 'Remove specific element. params: {elementId:string}',
            'wb_close' => 'Close whiteboard. params: {}',
            'discussion' => 'Trigger discussion. params: {topic, prompt?}',
        ];

        if ($actions === []) {
            return 'No actions available. Speak only.';
        }

        return collect($actions)
            ->filter(fn ($a) => isset($descriptions[$a]))
            ->map(fn ($a) => "- {$a}: {$descriptions[$a]}")
            ->implode("\n");
    }

    private function whiteboardGuidelines(string $role): string
    {
        if ($role === 'student') {
            return 'ONLY use the whiteboard when the teacher explicitly invites you (e.g. \'come solve this on the board\'). Never proactively draw on the whiteboard.';
        }

        return 'Canvas is 1000×562. Positions in this coordinate space. Ensure x+width≤1000, y+height≤562. Leave 20px gap between elements. Call wb_clear when board is crowded before adding new elements. Do NOT call wb_close at the end — leave whiteboard open for students to read.';
    }

    private function buildStateContext(ClassroomSession $session): string
    {
        $scene = $session->currentScene;
        $elements = $session->whiteboard_elements ?? [];
        $wbOpen = $session->whiteboard_open;
        $lines = [];

        $lines[] = 'Whiteboard: '.($wbOpen ? 'OPEN (slide canvas is hidden)' : 'closed');
        $lines[] = 'Whiteboard elements: '.count($elements);

        if ($scene) {
            $lines[] = "Current scene: \"{$scene->title}\" (type: {$scene->scene_type})";
            if ($scene->scene_type === 'slide') {
                $els = $scene->content['elements'] ?? [];
                $summary = collect($els)->map(function ($el) {
                    $snippet = strip_tags((string) ($el['content'] ?? ''));
                    if ($snippet === '' && isset($el['latex'])) {
                        $snippet = (string) $el['latex'];
                    }

                    return "[id:{$el['id']}] {$el['type']}: ".mb_substr($snippet, 0, 40);
                })->implode(', ');
                $lines[] = "Slide elements: {$summary}";
            }
            if ($scene->scene_type === 'quiz') {
                $qs = collect($scene->content['questions'] ?? [])
                    ->map(fn ($q, $i) => ($i + 1).'. '.$q['question'])
                    ->implode('; ');
                $lines[] = "Quiz questions: {$qs}";
            }
        }

        return implode("\n", $lines);
    }

    private function buildPeerContext(ClassroomSession $session, string $currentAgentName): string
    {
        $spoken = $session->getAgentsSpokenThisRound();
        $peers = array_filter($spoken, fn ($s) => ($s['name'] ?? '') !== $currentAgentName);
        if ($peers === []) {
            return '';
        }

        $lines = ['# What others already said this round (do NOT repeat):'];
        foreach ($peers as $peer) {
            $lines[] = "- {$peer['name']}: \"{$peer['preview']}\"";
        }
        $lines[] = 'Build on or question what was said. Do not repeat greetings.';

        return implode("\n", $lines);
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function buildMessages(ClassroomSession $session, string $studentMessage): array
    {
        $history = $session->messages()
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->reverse()
            ->values();

        $messages = [];
        foreach ($history as $msg) {
            if ($msg->sender_type === 'student') {
                $messages[] = ['role' => 'user', 'content' => (string) $msg->content_text];
            } elseif ($msg->sender_type === 'agent') {
                $messages[] = ['role' => 'assistant', 'content' => (string) ($msg->content_text ?? '')];
            }
        }

        if ($studentMessage !== '') {
            $messages[] = ['role' => 'user', 'content' => $studentMessage];
        }

        return $messages;
    }

    private function repairJson(string $json): string
    {
        $json = preg_replace('/,\s*([\]}])/', '$1', $json) ?? $json;
        $opens = substr_count($json, '[');
        $closes = substr_count($json, ']');
        if ($opens > $closes) {
            $json .= str_repeat(']', $opens - $closes);
        }

        return $json;
    }

    /**
     * @param  list<string>  $parts
     */
    private function mergeClassroomSafetyFlag(
        FlagResult $flag,
        string $text,
        array &$parts,
        ?FlagResult &$worst,
    ): void {
        $parts[] = $text;
        if ($worst === null || $this->safetySeverityRank($flag->severity) > $this->safetySeverityRank($worst->severity)) {
            $worst = $flag;
        }
    }

    private function safetySeverityRank(string $severity): int
    {
        return match ($severity) {
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            default => 1,
        };
    }
}

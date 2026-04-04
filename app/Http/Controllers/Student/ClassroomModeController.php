<?php

namespace App\Http\Controllers\Student;

use App\Events\ClassroomMessageSent;
use App\Events\ClassroomSessionEnded;
use App\Events\ClassroomSessionStarted;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\AuthorizesStudentLearningSpace;
use App\Models\ClassroomLesson;
use App\Models\ClassroomMessage;
use App\Models\ClassroomSession;
use App\Models\LessonQuizAttempt;
use App\Models\LearningSpace;
use App\Services\Classroom\AgentOrchestrator;
use App\Services\Classroom\DirectorService;
use App\Jobs\ProcessClassroomSafetyAlert;
use App\Services\Classroom\QuizGraderService;
use App\Services\Classroom\WhiteboardService;
use App\Services\Safety\ClassroomStudentSafetyCoordinator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 3d multi-agent classroom (distinct from Teacher\ClassroomController roster CRUD).
 */
class ClassroomModeController extends Controller
{
    use AuthorizesStudentLearningSpace;

    public function __construct(
        private DirectorService $director,
        private AgentOrchestrator $orchestrator,
        private WhiteboardService $whiteboard,
        private QuizGraderService $grader,
        private ClassroomStudentSafetyCoordinator $classroomSafety,
    ) {}

    public function start(Request $request, LearningSpace $space)
    {
        $this->authorizeStudentLearningSpace($request->user(), $space);

        $lesson = ClassroomLesson::query()
            ->where('space_id', $space->id)
            ->where('status', 'published')
            ->where('generation_status', 'completed')
            ->firstOrFail();

        $firstScene = $lesson->scenes()->orderBy('sequence_order')->first();

        $session = ClassroomSession::create([
            'district_id' => $request->user()->district_id,
            'lesson_id' => $lesson->id,
            'student_id' => $request->user()->id,
            'current_scene_id' => $firstScene?->id,
            'director_state' => [
                'turn_count' => 0,
                'rounds_without_input' => 0,
                'agents_spoken' => [],
                'whiteboard_ledger' => [],
            ],
            'whiteboard_elements' => [],
            'whiteboard_open' => false,
            'status' => 'active',
        ]);

        $session->load(['student', 'lesson.space']);
        ClassroomSessionStarted::dispatch($session);

        return redirect()->route('student.classroom.show', $session);
    }

    public function show(Request $request, ClassroomSession $session): Response
    {
        $this->authorize('view', $session);

        $session->load([
            'lesson.agents',
            'currentScene',
            'lesson.scenes' => fn ($q) => $q->orderBy('sequence_order'),
            'messages' => fn ($q) => $q->with('agent:id,display_name,avatar_emoji,color_hex')->orderBy('created_at'),
        ]);

        $messages = $session->messages->map(function (ClassroomMessage $m) {
            $row = [
                'id' => $m->id,
                'role' => $m->sender_type === 'student' ? 'student' : 'agent',
                'content' => $m->content_text ?? '',
            ];
            if ($m->sender_type === 'agent' && $m->agent_id) {
                $agent = $m->relationLoaded('agent') ? $m->agent : $m->agent()->first();
                if ($agent) {
                    $row['agentName'] = $agent->display_name;
                    $row['agentEmoji'] = $agent->avatar_emoji;
                    $row['agentColor'] = $agent->color_hex;
                }
            }

            return $row;
        });

        return Inertia::render('Student/Classroom', [
            'session' => $session,
            'lesson' => $session->lesson,
            'agents' => $session->lesson->agents->where('is_active', true)->values(),
            'initialMessages' => $messages,
        ]);
    }

    public function message(Request $request, ClassroomSession $session): StreamedResponse
    {
        $this->authorize('update', $session);

        $data = $request->validate([
            'content' => 'required|string|max:2000',
        ]);

        $studentMessage = $data['content'];

        [$preFlag, $earlyReply] = $this->classroomSafety->evaluateBeforeAgents($session, $studentMessage);
        if ($earlyReply !== null) {
            if (
                $preFlag
                && ! str_starts_with($preFlag->category, 'crisis_')
                && $preFlag->category !== 'academic_integrity'
                && $preFlag->category !== 'cheating_request'
                && $preFlag->category !== 'scope:off_topic'
                && in_array($preFlag->severity, ['critical', 'high'], true)
            ) {
                ProcessClassroomSafetyAlert::dispatch($session, $preFlag, $studentMessage);
            }

            return response()->stream(function () use ($earlyReply) {
                echo 'data: '.json_encode(['type' => 'text_delta', 'data' => ['content' => $earlyReply]])."\n\n";
                echo 'data: '.json_encode(['type' => 'done', 'data' => []])."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }, 200, $this->sseHeaders());
        }

        $scopeBlock = $this->classroomSafety->orchestratorScopeBlock($session);

        ClassroomMessage::create([
            'session_id' => $session->id,
            'district_id' => $session->district_id,
            'sender_type' => 'student',
            'content_text' => $studentMessage,
        ]);

        $this->director->startNewRound($session, studentReplied: true);
        $session->refresh();

        $agents = $session->lesson->agents()->where('is_active', true)->orderByDesc('priority')->get();
        $maxTurns = (int) config('classroom.max_turns_per_round', 3);

        $director = $this->director;
        $orchestrator = $this->orchestrator;

        return response()->stream(function () use ($session, $agents, $studentMessage, $maxTurns, $director, $orchestrator, $scopeBlock) {
            $send = function (array $event): void {
                echo 'data: '.json_encode($event)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $turnsThisRequest = 0;
            $lastAgentPreview = '';

            while ($turnsThisRequest < $maxTurns) {
                $session->refresh();

                $nextId = $director->nextAgentId($session, $agents->all());

                if ($nextId === null) {
                    break;
                }

                if ($nextId === 'USER') {
                    $send(['type' => 'cue_user', 'data' => [
                        'prompt' => $this->generateCuePrompt($session),
                    ]]);
                    break;
                }

                $agent = $agents->firstWhere('id', $nextId);
                if (! $agent) {
                    break;
                }

                $send(['type' => 'thinking', 'data' => ['stage' => 'agent_loading', 'agentId' => $agent->id]]);

                $allText = '';
                $allActions = [];

                foreach ($orchestrator->generateTurn($session, $agent, $studentMessage, $scopeBlock) as $event) {
                    $send($event);
                    if ($event['type'] === 'text_delta') {
                        $allText .= $event['data']['content'] ?? '';
                    }
                    if ($event['type'] === 'action') {
                        $allActions[] = $event['data'];
                    }

                    if ($event['type'] === 'action' && ($event['data']['actionName'] ?? '') === 'discussion') {
                        $topic = (string) ($event['data']['params']['topic'] ?? '');
                        $prompt = $event['data']['params']['prompt'] ?? null;
                        $session->update([
                            'session_type' => 'discussion',
                            'director_state' => array_merge($session->director_state ?? [], [
                                'discussion_topic' => $topic,
                                'discussion_prompt' => $prompt,
                            ]),
                        ]);
                    }
                }

                ClassroomMessage::create([
                    'session_id' => $session->id,
                    'district_id' => $session->district_id,
                    'sender_type' => 'agent',
                    'agent_id' => $agent->id,
                    'content_text' => $allText,
                    'actions_json' => $allActions,
                ]);

                $lastAgentPreview = mb_substr($allText, 0, 70);

                $turnsThisRequest++;
                $session->refresh();
            }

            $session->load(['student', 'lesson.space']);
            $preview = $lastAgentPreview !== '' ? $lastAgentPreview : mb_substr($studentMessage, 0, 70);
            ClassroomMessageSent::dispatch(
                $session,
                $preview,
                ClassroomMessage::query()->where('session_id', $session->id)->count(),
            );

            $send(['type' => 'done', 'data' => ['totalAgents' => $turnsThisRequest]]);
        }, 200, $this->sseHeaders());
    }

    private function generateCuePrompt(ClassroomSession $session): string
    {
        $scene = $session->currentScene;

        if ($scene?->scene_type === 'quiz') {
            return 'Take your time with the question above.';
        }
        if ($scene?->scene_type === 'discussion') {
            return 'What do you think? Share your thoughts.';
        }

        $state = $session->director_state ?? [];
        $spoken = $state['agents_spoken'] ?? [];
        $lastAgent = $spoken !== [] ? end($spoken) : null;

        if ($lastAgent && str_contains((string) ($lastAgent['preview'] ?? ''), '?')) {
            return 'Take a moment to think about that question.';
        }

        return 'What questions do you have so far?';
    }

    public function advance(Request $request, ClassroomSession $session)
    {
        $this->authorize('update', $session);

        $data = $request->validate([
            'scene_id' => 'nullable|uuid',
        ]);

        $session->loadMissing('lesson');
        $lesson = $session->lesson;
        $scenes = $lesson->scenes()->orderBy('sequence_order')->get();

        if ($scenes->isEmpty()) {
            return response()->json([
                'current_scene_id' => $session->current_scene_id,
                'lesson_complete' => true,
            ]);
        }

        if ($targetId = $data['scene_id'] ?? null) {
            $target = $scenes->firstWhere('id', $targetId);
            abort_if($target === null, 422, 'Invalid scene for this lesson.');

            $nextId = $target->id;
        } else {
            $currentId = $session->current_scene_id;
            $idx = $scenes->search(fn ($s) => $s->id === $currentId);
            if ($idx === false) {
                $nextId = $scenes->first()->id;
            } else {
                $next = $scenes->get($idx + 1);
                if ($next === null) {
                    return response()->json([
                        'current_scene_id' => $session->current_scene_id,
                        'lesson_complete' => true,
                    ]);
                }
                $nextId = $next->id;
            }
        }

        if ($session->current_scene_id && $session->current_scene_id !== $nextId) {
            $this->whiteboard->snapshot($session, $session->current_scene_id);
        }

        $session->update([
            'current_scene_id' => $nextId,
            'current_scene_action_index' => 0,
        ]);

        return response()->json([
            'current_scene_id' => $nextId,
            'lesson_complete' => false,
        ]);
    }

    public function whiteboard(Request $request, ClassroomSession $session)
    {
        $this->authorize('view', $session);

        return response()->json($this->whiteboard->getState($session));
    }

    public function submitQuiz(Request $request, ClassroomSession $session, string $scene)
    {
        $this->authorize('update', $session);

        $sceneModel = $session->lesson->scenes()->whereKey($scene)->firstOrFail();

        $data = $request->validate([
            'question_index' => 'required|integer',
            'answer' => 'required',
        ]);

        $questions = $sceneModel->content['questions'] ?? [];
        $question = $questions[$data['question_index']] ?? null;
        if (! $question) {
            return response()->json(['error' => 'Question not found'], 404);
        }

        $answerPayload = $data['answer'];
        if (! is_array($answerPayload)) {
            $answerPayload = [$answerPayload];
        }

        $attempt = LessonQuizAttempt::create([
            'session_id' => $session->id,
            'district_id' => $session->district_id,
            'scene_id' => $sceneModel->id,
            'question_index' => $data['question_index'],
            'question_type' => $question['type'],
            'student_answer' => $answerPayload,
            'max_score' => $question['points'] ?? 10,
        ]);

        $result = $this->grader->grade($attempt, $question);

        $attempt->update([
            'is_correct' => $result['is_correct'],
            'score' => $result['score'],
            'llm_feedback' => $result['feedback'],
            'graded_at' => now(),
        ]);

        return response()->json([
            'is_correct' => $result['is_correct'],
            'score' => $result['score'],
            'max_score' => $result['max_score'],
            'feedback' => $result['feedback'],
            'analysis' => $question['analysis'] ?? null,
        ]);
    }

    public function end(Request $request, ClassroomSession $session)
    {
        $this->authorize('update', $session);

        if ($session->current_scene_id) {
            $this->whiteboard->snapshot($session, $session->current_scene_id);
        }

        $session->update(['status' => 'completed', 'ended_at' => now()]);

        $session->load(['lesson.space']);
        ClassroomSessionEnded::dispatch($session);

        $spaceId = $session->lesson->space_id;
        if ($spaceId) {
            return redirect()->route('student.spaces.show', $spaceId);
        }

        return redirect()->route('student.dashboard');
    }

    /**
     * @return array<string, string>
     */
    private function sseHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ];
    }
}

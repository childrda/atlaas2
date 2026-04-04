# ATLAAS — Phase 3d: Multi-Agent Classroom Mode
## Prerequisite: Phases 1–3c checklist fully passing
## Stop when this works: A teacher can generate a lesson from a topic or PDF,
## configure AI agents, and a student can experience a live multi-agent classroom
## with slides, whiteboard, quiz, and interactive simulation scenes.

---

## What you're building in this phase

- **Two-stage lesson generation pipeline** — outline (Stage 1) then scene content
  (Stage 2) generated in parallel Horizon jobs
- **Director orchestration** — lightweight state machine decides which agent speaks
  next (pure code for single-agent, LLM for multi-agent)
- **Agent orchestrator** — builds per-agent system prompts, calls LLM with streaming
  JSON array output, parses actions and text incrementally
- **Whiteboard service** — maintains a 1000×562 coordinate-space element array,
  pushed to the student via Reverb
- **Quiz grading** — deterministic for MC, LLM-powered for short answer
- **Interactive HTML processor** — validates and post-processes LLM-generated HTML
  simulations for safe sandboxed iframe delivery
- **Four scene types** — slide, quiz, interactive, discussion
- **Full teacher lesson builder UI** — source input → agent config → generation
  progress → scene review and edit → publish to Space
- **Full student classroom UI** — three-panel layout with agent panel, main content,
  and whiteboard

**PBL (project-based learning) scenes are deferred to Phase 3d.2.**

---

## Architecture summary (read before coding)

OpenMAIC's backend is stateless — the client sends all state on every request.
ATLAAS does the opposite: all session state lives server-side in the database
and Redis. The student sends only their message; the server holds director state,
whiteboard state, and conversation history in the `classroom_sessions` table.

Agent responses are a **JSON array** of typed items:
```json
[
  {"type":"action","name":"spotlight","params":{"elementId":"title_001"}},
  {"type":"text","content":"Today we're going to explore the water cycle."},
  {"type":"action","name":"wb_open","params":{}},
  {"type":"action","name":"wb_draw_text","params":{"content":"Evaporation","x":100,"y":100,"fontSize":24}},
  {"type":"text","content":"Evaporation is the first stage — water becomes vapour."}
]
```

Text and actions freely interleave. The PHP streaming parser reads this
incrementally from the LLM stream and emits SSE events as items complete.

---

## Step 1 — Database migrations

### Migration: create_classroom_lessons_table
```php
Schema::create('classroom_lessons', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('district_id');
    $table->uuid('teacher_id');
    $table->uuid('space_id')->nullable();
    $table->string('title');
    $table->string('subject')->nullable();
    $table->string('grade_level')->nullable();
    $table->string('language', 10)->default('en');
    $table->enum('source_type', ['topic', 'pdf', 'standard'])->default('topic');
    $table->longText('source_text')->nullable();
    $table->string('source_file_path')->nullable();
    $table->string('generation_job_id')->nullable();
    $table->enum('generation_status', [
        'pending','generating_outline','generating_scenes',
        'generating_media','completed','failed'
    ])->default('pending');
    $table->jsonb('generation_progress')->nullable();
    $table->jsonb('outline')->nullable();
    $table->enum('agent_mode', ['default','custom'])->default('default');
    $table->enum('status', ['draft','published','archived'])->default('draft');
    $table->timestamp('published_at')->nullable();
    $table->foreign('district_id')->references('id')->on('districts');
    $table->foreign('teacher_id')->references('id')->on('users');
    $table->foreign('space_id')->references('id')->on('learning_spaces')->nullOnDelete();
    $table->timestamps();
    $table->softDeletes();
    $table->index(['district_id', 'teacher_id', 'status']);
});
```

### Migration: create_lesson_agents_table
```php
Schema::create('lesson_agents', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('lesson_id');
    $table->uuid('district_id');
    $table->enum('role', ['teacher','assistant','student']);
    $table->string('display_name');
    $table->string('archetype'); // teacher|assistant|curious|notetaker|skeptic|enthusiast
    $table->string('avatar_emoji', 10)->default('🤖');
    $table->string('color_hex', 10)->default('#1E3A5F');
    $table->text('persona_text');
    $table->jsonb('allowed_actions');
    $table->unsignedSmallInteger('priority')->default(5);
    $table->unsignedSmallInteger('sequence_order')->default(0);
    $table->boolean('is_active')->default(true);
    $table->text('system_prompt_addendum')->nullable();
    $table->foreign('lesson_id')->references('id')->on('classroom_lessons')->cascadeOnDelete();
    $table->timestamps();
    $table->index(['lesson_id', 'sequence_order']);
});
```

### Migration: create_lesson_scenes_table
```php
Schema::create('lesson_scenes', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('lesson_id');
    $table->uuid('district_id');
    $table->unsignedSmallInteger('sequence_order');
    $table->enum('scene_type', ['slide','quiz','interactive','pbl','discussion']);
    $table->string('title');
    $table->string('learning_objective')->nullable();
    $table->unsignedInteger('estimated_duration_seconds')->default(120);
    $table->jsonb('outline_data')->nullable();
    $table->jsonb('content')->nullable();   // slide elements, quiz questions, or html
    $table->jsonb('actions')->nullable();   // pre-generated action sequence for playback
    $table->enum('generation_status', ['pending','generating','ready','error'])->default('pending');
    $table->string('generation_error')->nullable();
    $table->foreign('lesson_id')->references('id')->on('classroom_lessons')->cascadeOnDelete();
    $table->timestamps();
    $table->index(['lesson_id', 'sequence_order']);
});
```

### Migration: create_classroom_sessions_table
```php
Schema::create('classroom_sessions', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('district_id');
    $table->uuid('lesson_id');
    $table->uuid('student_id');
    $table->uuid('current_scene_id')->nullable();
    $table->unsignedSmallInteger('current_scene_action_index')->default(0);
    $table->jsonb('director_state')->nullable();
    // director_state shape: {turn_count, agents_spoken:[{agent_id,name,preview,action_count}], whiteboard_ledger:[...]}
    $table->jsonb('whiteboard_elements')->nullable(); // current wb state
    $table->boolean('whiteboard_open')->default(false);
    $table->enum('session_type', ['lecture','qa','discussion'])->default('lecture');
    $table->enum('status', ['active','completed','abandoned'])->default('active');
    $table->timestamp('started_at')->useCurrent();
    $table->timestamp('ended_at')->nullable();
    $table->text('student_summary')->nullable();
    $table->text('teacher_summary')->nullable();
    $table->foreign('lesson_id')->references('id')->on('classroom_lessons');
    $table->foreign('student_id')->references('id')->on('users');
    $table->timestamps();
    $table->index(['district_id', 'lesson_id', 'status']);
    $table->index(['student_id', 'started_at']);
});
```

### Migration: create_classroom_messages_table
```php
Schema::create('classroom_messages', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('session_id');
    $table->uuid('district_id');
    $table->enum('sender_type', ['student','agent','system']);
    $table->uuid('agent_id')->nullable();
    $table->text('content_text')->nullable();   // plain text of speech only
    $table->jsonb('actions_json')->nullable();   // full action array
    $table->boolean('flagged')->default(false);
    $table->string('flag_reason')->nullable();
    $table->foreign('session_id')->references('id')->on('classroom_sessions')->cascadeOnDelete();
    $table->timestamp('created_at')->useCurrent();
    $table->index(['session_id', 'created_at']);
    $table->index(['district_id', 'flagged']);
});
```

### Migration: create_lesson_quiz_attempts_table
```php
Schema::create('lesson_quiz_attempts', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('session_id');
    $table->uuid('district_id');
    $table->uuid('scene_id');
    $table->unsignedSmallInteger('question_index');
    $table->enum('question_type', ['single','multiple','short_answer']);
    $table->jsonb('student_answer'); // array of selected values or string
    $table->boolean('is_correct')->nullable();
    $table->decimal('score', 4, 2)->default(0);
    $table->decimal('max_score', 4, 2)->default(1);
    $table->text('llm_feedback')->nullable();
    $table->timestamp('graded_at')->nullable();
    $table->timestamps();
    $table->index(['session_id', 'scene_id']);
});
```

### Migration: create_whiteboard_snapshots_table
```php
Schema::create('whiteboard_snapshots', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('session_id');
    $table->uuid('scene_id');
    $table->jsonb('elements'); // whiteboard elements array at scene end
    $table->timestamp('created_at')->useCurrent();
    $table->index(['session_id', 'scene_id']);
});
```

Run all migrations:
```bash
php artisan migrate
```

---

## Step 2 — Models

### `app/Models/ClassroomLesson.php`
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Models\Scopes\DistrictScope;

class ClassroomLesson extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'district_id', 'teacher_id', 'space_id', 'title', 'subject',
        'grade_level', 'language', 'source_type', 'source_text',
        'source_file_path', 'generation_job_id', 'generation_status',
        'generation_progress', 'outline', 'agent_mode', 'status', 'published_at',
    ];

    protected $casts = [
        'generation_progress' => 'array',
        'outline'             => 'array',
        'published_at'        => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new DistrictScope);
    }

    public function scenes() { return $this->hasMany(LessonScene::class, 'lesson_id')->orderBy('sequence_order'); }
    public function agents() { return $this->hasMany(LessonAgent::class, 'lesson_id')->orderBy('sequence_order'); }
    public function sessions() { return $this->hasMany(ClassroomSession::class, 'lesson_id'); }
    public function teacher() { return $this->belongsTo(User::class, 'teacher_id'); }
    public function space() { return $this->belongsTo(LearningSpace::class, 'space_id'); }

    public function isGenerationComplete(): bool
    {
        return $this->generation_status === 'completed';
    }
}
```

### `app/Models/LessonAgent.php`
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class LessonAgent extends Model
{
    use HasUuids;

    protected $fillable = [
        'lesson_id', 'district_id', 'role', 'display_name', 'archetype',
        'avatar_emoji', 'color_hex', 'persona_text', 'allowed_actions',
        'priority', 'sequence_order', 'is_active', 'system_prompt_addendum',
    ];

    protected $casts = ['allowed_actions' => 'array'];

    public function lesson() { return $this->belongsTo(ClassroomLesson::class, 'lesson_id'); }

    // Actions allowed for this agent filtered by scene type
    public function effectiveActions(string $sceneType): array
    {
        $slideOnly = ['spotlight', 'laser'];
        $all = $this->allowed_actions ?? [];
        if ($sceneType !== 'slide') {
            $all = array_values(array_filter($all, fn($a) => !in_array($a, $slideOnly)));
        }
        return $all;
    }
}
```

### `app/Models/LessonScene.php`
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class LessonScene extends Model
{
    use HasUuids;

    protected $fillable = [
        'lesson_id', 'district_id', 'sequence_order', 'scene_type',
        'title', 'learning_objective', 'estimated_duration_seconds',
        'outline_data', 'content', 'actions', 'generation_status', 'generation_error',
    ];

    protected $casts = [
        'outline_data' => 'array',
        'content'      => 'array',
        'actions'      => 'array',
    ];

    public function lesson() { return $this->belongsTo(ClassroomLesson::class, 'lesson_id'); }
    public function quizAttempts() { return $this->hasMany(LessonQuizAttempt::class, 'scene_id'); }
}
```

### `app/Models/ClassroomSession.php`
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Models\Scopes\DistrictScope;

class ClassroomSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'district_id', 'lesson_id', 'student_id', 'current_scene_id',
        'current_scene_action_index', 'director_state', 'whiteboard_elements',
        'whiteboard_open', 'session_type', 'status', 'started_at', 'ended_at',
        'student_summary', 'teacher_summary',
    ];

    protected $casts = [
        'director_state'      => 'array',
        'whiteboard_elements' => 'array',
        'whiteboard_open'     => 'boolean',
        'started_at'          => 'datetime',
        'ended_at'            => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new DistrictScope);
    }

    public function lesson() { return $this->belongsTo(ClassroomLesson::class, 'lesson_id'); }
    public function student() { return $this->belongsTo(User::class, 'student_id'); }
    public function currentScene() { return $this->belongsTo(LessonScene::class, 'current_scene_id'); }
    public function messages() { return $this->hasMany(ClassroomMessage::class, 'session_id')->orderBy('created_at'); }
    public function quizAttempts() { return $this->hasMany(LessonQuizAttempt::class, 'session_id'); }

    public function getDirectorTurnCount(): int
    {
        return $this->director_state['turn_count'] ?? 0;
    }

    public function getAgentsSpokenThisRound(): array
    {
        return $this->director_state['agents_spoken'] ?? [];
    }

    public function getWhiteboardLedger(): array
    {
        return $this->director_state['whiteboard_ledger'] ?? [];
    }
}
```

### `app/Models/ClassroomMessage.php`
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ClassroomMessage extends Model
{
    use HasUuids;

    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'session_id', 'district_id', 'sender_type', 'agent_id',
        'content_text', 'actions_json', 'flagged', 'flag_reason',
    ];

    protected $casts = ['actions_json' => 'array'];

    public function session() { return $this->belongsTo(ClassroomSession::class, 'session_id'); }
    public function agent() { return $this->belongsTo(LessonAgent::class, 'agent_id'); }
}
```

### `app/Models/LessonQuizAttempt.php`
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class LessonQuizAttempt extends Model
{
    use HasUuids;

    protected $fillable = [
        'session_id', 'district_id', 'scene_id', 'question_index',
        'question_type', 'student_answer', 'is_correct', 'score',
        'max_score', 'llm_feedback', 'graded_at',
    ];

    protected $casts = [
        'student_answer' => 'array',
        'is_correct'     => 'boolean',
        'score'          => 'float',
        'max_score'      => 'float',
        'graded_at'      => 'datetime',
    ];
}
```

---

## Step 3 — Agent archetype definitions

Create `app/Services/Classroom/AgentArchetypes.php`:

```php
<?php
namespace App\Services\Classroom;

class AgentArchetypes
{
    // Default allowed actions per role
    const WHITEBOARD_ACTIONS = [
        'wb_open','wb_close','wb_draw_text','wb_draw_shape',
        'wb_draw_chart','wb_draw_latex','wb_draw_table','wb_draw_line',
        'wb_clear','wb_delete',
    ];

    const SLIDE_ACTIONS = ['spotlight', 'laser', 'play_video'];

    const ROLE_ACTIONS = [
        'teacher'   => ['spotlight','laser','play_video',
                        'wb_open','wb_close','wb_draw_text','wb_draw_shape',
                        'wb_draw_chart','wb_draw_latex','wb_draw_table',
                        'wb_draw_line','wb_clear','wb_delete'],
        'assistant' => ['wb_open','wb_close','wb_draw_text','wb_draw_shape',
                        'wb_draw_chart','wb_draw_latex','wb_draw_table',
                        'wb_draw_line','wb_clear','wb_delete'],
        'student'   => ['wb_open','wb_close','wb_draw_text','wb_draw_shape',
                        'wb_draw_chart','wb_draw_latex','wb_draw_table',
                        'wb_draw_line','wb_clear','wb_delete'],
    ];

    // K-12 appropriate agent archetypes
    const ARCHETYPES = [
        'teacher' => [
            'role'         => 'teacher',
            'display_name' => 'Teacher',
            'avatar_emoji' => '👩‍🏫',
            'color_hex'    => '#1E3A5F',
            'priority'     => 10,
            'persona_text' => "You are a warm, patient, and encouraging teacher. You genuinely love your subject and care deeply about whether your students understand.\n\nYour teaching style: Explain step by step. Use simple analogies and real-world examples that students can relate to. Ask questions to check understanding rather than just lecturing. When something is complex, slow down. Celebrate effort, not just correctness.\n\nYou never talk down to students. You meet them where they are.",
        ],
        'assistant' => [
            'role'         => 'assistant',
            'display_name' => 'Teaching Assistant',
            'avatar_emoji' => '🤝',
            'color_hex'    => '#10b981',
            'priority'     => 7,
            'persona_text' => "You are the teaching assistant — the helpful guide who fills in the gaps.\n\nYour role: When students look confused, rephrase things more simply. You add quick examples, background context, and practical tips. You are brief — one clear point at a time. You support the teacher, not replace them.\n\nYou speak like a helpful older student who just figured something out and wants to share it simply.",
        ],
        'curious' => [
            'role'         => 'student',
            'display_name' => 'Sam',
            'avatar_emoji' => '🤔',
            'color_hex'    => '#ec4899',
            'priority'     => 5,
            'persona_text' => "You are the student who always has one more question.\n\nYou ask \"why?\" and \"but what if...?\" You notice things others miss. You are not afraid to say \"I don't get it\" — your honesty helps everyone. You get genuinely excited when something clicks.\n\nKeep it SHORT. One question or reaction at a time. You are a student, not a teacher. Speak naturally, like you're actually sitting in class.",
        ],
        'notetaker' => [
            'role'         => 'student',
            'display_name' => 'Alex',
            'avatar_emoji' => '📝',
            'color_hex'    => '#06b6d4',
            'priority'     => 5,
            'persona_text' => "You are the student who organizes everything.\n\nYou listen carefully and love to summarize. After a key point, you offer a quick recap. You notice when something important was said but might be missed.\n\nKeep it SHORT. A quick structured summary — not a paragraph. You speak clearly and directly.",
        ],
        'skeptic' => [
            'role'         => 'student',
            'display_name' => 'Jordan',
            'avatar_emoji' => '🧐',
            'color_hex'    => '#8b5cf6',
            'priority'     => 4,
            'persona_text' => "You are the student who questions everything — in a good way.\n\nYou push back gently: \"Is that always true?\" \"What about...?\" You help the class think more deeply. You are curious, not combative.\n\nKeep it SHORT. One pointed question or observation. You provoke thought without taking over.",
        ],
        'enthusiast' => [
            'role'         => 'student',
            'display_name' => 'Riley',
            'avatar_emoji' => '🌟',
            'color_hex'    => '#f59e0b',
            'priority'     => 4,
            'persona_text' => "You are the student who connects everything.\n\nYou get excited about links between this topic and other things. \"Oh! This is like...\" Your energy is contagious.\n\nKeep it SHORT. A quick excited connection or observation. Keep the energy up without going off-track.",
        ],
    ];

    public static function get(string $archetype): array
    {
        return self::ARCHETYPES[$archetype] ?? self::ARCHETYPES['teacher'];
    }

    public static function defaultAgentsForLesson(string $gradeLevel = ''): array
    {
        // K-2: teacher + curious student only (simpler classroom)
        if (in_array($gradeLevel, ['K','1','2'])) {
            return ['teacher', 'curious'];
        }
        // 3-5: teacher + assistant + curious
        if (in_array($gradeLevel, ['3','4','5'])) {
            return ['teacher', 'assistant', 'curious'];
        }
        // 6+: teacher + assistant + curious + skeptic
        return ['teacher', 'assistant', 'curious', 'skeptic'];
    }
}
```

---

## Step 4 — Interactive HTML processor

Create `app/Services/Classroom/InteractiveHtmlProcessor.php`:

```php
<?php
namespace App\Services\Classroom;

class InteractiveHtmlProcessor
{
    // Patterns that are forbidden in student-facing simulations
    const FORBIDDEN_PATTERNS = [
        '/\bfetch\s*\(/i',
        '/\bXMLHttpRequest\b/i',
        '/\bnavigator\.sendBeacon\b/i',
        '/\bnew\s+WebSocket\b/i',
        '/\beval\s*\(/i',
        '/\bnew\s+Function\s*\(/i',
    ];

    /**
     * Post-process LLM-generated interactive HTML for safe student delivery.
     * Returns null if HTML fails validation (caller falls back to slide type).
     */
    public function process(string $html): ?string
    {
        // 1. Strip any external script src or link href (remove CDN dependencies)
        $html = $this->stripExternalResources($html);

        // 2. Convert LaTeX delimiters protecting script blocks
        $html = $this->convertLatexDelimiters($html);

        // 3. Inject KaTeX for formula rendering
        if (stripos($html, 'katex') === false) {
            $html = $this->injectKatex($html);
        }

        // 4. Inject iframe CSS patch
        $html = $this->injectIframeCss($html);

        // 5. Validate — no dangerous patterns
        if (!$this->validate($html)) {
            return null;
        }

        return $html;
    }

    private function stripExternalResources(string $html): string
    {
        // Remove external script src (non-CDN)
        // We allow jsdelivr.net (for KaTeX which we inject ourselves)
        $html = preg_replace(
            '/<script[^>]+src=["\'](?!https:\/\/cdn\.jsdelivr\.net)[^"\']+["\'][^>]*>.*?<\/script>/is',
            '',
            $html
        );
        // Remove external stylesheet links
        $html = preg_replace(
            '/<link[^>]+href=["\'](?!https:\/\/cdn\.jsdelivr\.net)[^"\']*\.(css)["\'][^>]*\/?>/i',
            '',
            $html
        );
        return $html;
    }

    private function convertLatexDelimiters(string $html): string
    {
        // Protect script blocks
        $scriptBlocks = [];
        $html = preg_replace_callback(
            '/<script[^>]*>.*?<\/script>/is',
            function ($matches) use (&$scriptBlocks) {
                $placeholder = '__SCRIPT_BLOCK_' . count($scriptBlocks) . '__';
                $scriptBlocks[] = $matches[0];
                return $placeholder;
            },
            $html
        );

        // Convert $$...$$ → \[...\]
        $html = preg_replace('/\$\$([^$]+)\$\$/s', '\\[$1\\]', $html);
        // Convert $...$ → \(...\)
        $html = preg_replace('/\$([^$\n]+?)\$/', '\\($1\\)', $html);

        // Restore script blocks
        foreach ($scriptBlocks as $i => $block) {
            $html = str_replace('__SCRIPT_BLOCK_' . $i . '__', $block, $html);
        }

        return $html;
    }

    private function injectKatex(string $html): string
    {
        $katex = "\n" . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">' . "\n"
            . '<script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js"></script>' . "\n"
            . '<script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js"></script>' . "\n"
            . '<script>document.addEventListener("DOMContentLoaded",function(){renderMathInElement(document.body,{delimiters:[{left:"\\\\[",right:"\\\\]",display:true},{left:"\\\\(",right:"\\\\)",display:false}]})});</script>' . "\n";

        $pos = stripos($html, '</head>');
        if ($pos !== false) {
            return substr($html, 0, $pos) . $katex . substr($html, $pos);
        }
        return $katex . $html;
    }

    private function injectIframeCss(string $html): string
    {
        $css = "\n<style data-iframe-patch>html,body{width:100%;height:100%;margin:0;padding:0;overflow-x:hidden;overflow-y:auto;}body{min-height:100vh;}</style>\n";
        $pos = stripos($html, '<head>');
        if ($pos !== false) {
            $insertAt = $pos + 6;
            return substr($html, 0, $insertAt) . $css . substr($html, $insertAt);
        }
        return $css . $html;
    }

    private function validate(string $html): bool
    {
        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $html)) {
                return false;
            }
        }
        return true;
    }
}
```

---

## Step 5 — Lesson generator service

Create `app/Services/Classroom/LessonGeneratorService.php`:

```php
<?php
namespace App\Services\Classroom;

use App\Models\ClassroomLesson;
use App\Models\LessonScene;
use App\Services\AI\LLMService;
use Illuminate\Support\Str;

class LessonGeneratorService
{
    public function __construct(
        private LLMService $llm,
        private InteractiveHtmlProcessor $htmlProcessor,
    ) {}

    // ── Stage 1: Outline ────────────────────────────────────────────────

    public function generateOutline(ClassroomLesson $lesson): void
    {
        $lesson->update(['generation_status' => 'generating_outline']);

        $systemPrompt = $this->outlineSystemPrompt($lesson);
        $userPrompt   = $this->outlineUserPrompt($lesson);

        $response = $this->llm->complete($systemPrompt, $userPrompt);
        $outlines = $this->parseJsonArray($response);

        if (empty($outlines)) {
            $lesson->update(['generation_status' => 'failed',
                'generation_progress' => ['message' => 'Failed to parse outline']]);
            return;
        }

        // Enrich and save
        foreach ($outlines as $i => &$outline) {
            $outline['id']    = $outline['id'] ?? Str::uuid()->toString();
            $outline['order'] = $i + 1;
        }

        $lesson->update([
            'outline'             => $outlines,
            'generation_status'   => 'generating_scenes',
            'generation_progress' => [
                'step'            => 'generating_scenes',
                'progress'        => 30,
                'total_scenes'    => count($outlines),
                'scenes_generated'=> 0,
            ],
        ]);

        // Create scene records
        foreach ($outlines as $outline) {
            LessonScene::create([
                'lesson_id'          => $lesson->id,
                'district_id'        => $lesson->district_id,
                'sequence_order'     => $outline['order'],
                'scene_type'         => $outline['type'] ?? 'slide',
                'title'              => $outline['title'] ?? 'Scene',
                'learning_objective' => $outline['teachingObjective'] ?? null,
                'estimated_duration_seconds' => $outline['estimatedDuration'] ?? 120,
                'outline_data'       => $outline,
                'generation_status'  => 'pending',
            ]);
        }
    }

    private function outlineSystemPrompt(ClassroomLesson $lesson): string
    {
        $gradeLevel = $lesson->grade_level ?? 'general';
        $language   = $lesson->language === 'es' ? 'Spanish' : 'English';

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
        $grade  = $lesson->grade_level ? "Grade level: {$lesson->grade_level}" : '';
        $subj   = $lesson->subject ? "Subject: {$lesson->subject}" : '';

        return "Generate a lesson outline for:\n\n{$source}\n\n{$grade}\n{$subj}\n\nOutput JSON array only.";
    }

    // ── Stage 2A: Scene content ──────────────────────────────────────────

    public function generateSceneContent(LessonScene $scene): void
    {
        $scene->update(['generation_status' => 'generating']);

        try {
            $content = match($scene->scene_type) {
                'slide'       => $this->generateSlideContent($scene),
                'quiz'        => $this->generateQuizContent($scene),
                'interactive' => $this->generateInteractiveContent($scene),
                'discussion'  => $this->generateDiscussionContent($scene),
                default       => $this->generateSlideContent($scene),
            };

            if ($content === null) {
                // Fallback to slide for failed interactive
                if ($scene->scene_type === 'interactive') {
                    $content = $this->generateSlideContent($scene);
                    $scene->scene_type = 'slide';
                }
            }

            $actions = $this->generateActionSequence($scene, $content);

            $scene->update([
                'content'           => $content,
                'actions'           => $actions,
                'generation_status' => 'ready',
            ]);
        } catch (\Throwable $e) {
            $scene->update([
                'generation_status' => 'error',
                'generation_error'  => $e->getMessage(),
            ]);
        }
    }

    private function generateSlideContent(LessonScene $scene): array
    {
        $outline = $scene->outline_data ?? [];
        $systemPrompt = $this->slideContentSystemPrompt();
        $userPrompt   = $this->slideContentUserPrompt($scene, $outline);

        $response = $this->llm->complete($systemPrompt, $userPrompt);
        $parsed   = $this->parseJsonObject($response);

        return [
            'type'       => 'slide',
            'elements'   => $parsed['elements'] ?? [],
            'background' => $parsed['background'] ?? ['type' => 'solid', 'color' => '#ffffff'],
        ];
    }

    private function slideContentSystemPrompt(): string
    {
        return <<<PROMPT
You are an educational slide designer for K-12 classrooms.

Canvas: 960 × 540 px. Safe margins: left≥60, right≤900, top≥50, bottom≤490.

## Slide philosophy
Slides are visual aids, NOT lecture scripts. Keep text SHORT.
- Keywords and short bullet points only
- Full sentences go in the teacher's spoken narration, not on the slide
- Max ~20 words per bullet point

## Element types
TextElement: {id, type:"text", left, top, width, height, content:"<p style='font-size:Npx'>...</p>", defaultColor:"#333"}
ImageElement: {id, type:"image", left, top, width, height, src:"placeholder"}
ShapeElement: {id, type:"shape", left, top, width, height, fixedRatio:false, viewBox:"0 0 1000 1000", path:"M0 0...", fill:"#color"}

## Output
{"background":{"type":"solid","color":"#ffffff"},"elements":[...]}
JSON only, no explanation, no code fences.
PROMPT;
    }

    private function slideContentUserPrompt(LessonScene $scene, array $outline): string
    {
        $keyPoints = implode("\n- ", $outline['keyPoints'] ?? []);
        return "Title: {$scene->title}\nDescription: " . ($outline['description'] ?? '') . "\nKey points:\n- {$keyPoints}\n\nGenerate slide JSON only.";
    }

    private function generateQuizContent(LessonScene $scene): array
    {
        $outline     = $scene->outline_data ?? [];
        $quizConfig  = $outline['quizConfig'] ?? ['questionCount' => 3, 'difficulty' => 'medium', 'questionTypes' => ['single']];
        $count       = $quizConfig['questionCount'] ?? 3;
        $difficulty  = $quizConfig['difficulty'] ?? 'medium';
        $types       = implode(', ', $quizConfig['questionTypes'] ?? ['single']);

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

        $userPrompt = "Topic: {$scene->title}\nGenerate {$count} questions using types: {$types}\nKey points covered: " . implode(', ', $outline['keyPoints'] ?? []) . "\n\nOutput JSON array only.";

        $response  = $this->llm->complete($systemPrompt, $userPrompt);
        $questions = $this->parseJsonArray($response);

        return ['type' => 'quiz', 'questions' => $questions];
    }

    private function generateInteractiveContent(LessonScene $scene): ?array
    {
        $outline = $scene->outline_data ?? [];
        $config  = $outline['interactiveConfig'] ?? null;
        if (!$config) return null;

        // Step 1: Scientific model
        $modelSystemPrompt = <<<PROMPT
You are a science education expert. Produce a scientific model for the concept.
Output JSON only:
{"core_formulas":["..."],"mechanism":["..."],"constraints":["..."],"forbidden_errors":["..."]}
2-5 items per array. Be specific. No extra text.
PROMPT;
        $modelUserPrompt = "Concept: {$config['conceptName']}\nSubject: " . ($config['subject'] ?? '') . "\nOverview: {$config['conceptOverview']}";
        $modelResponse   = $this->llm->complete($modelSystemPrompt, $modelUserPrompt);
        $scientificModel = $this->parseJsonObject($modelResponse);

        $constraints = '';
        if (!empty($scientificModel)) {
            $lines = [];
            if (!empty($scientificModel['core_formulas']))    $lines[] = 'Formulas: '    . implode('; ', $scientificModel['core_formulas']);
            if (!empty($scientificModel['mechanism']))        $lines[] = 'Mechanisms: '  . implode('; ', $scientificModel['mechanism']);
            if (!empty($scientificModel['constraints']))      $lines[] = 'Must obey: '   . implode('; ', $scientificModel['constraints']);
            if (!empty($scientificModel['forbidden_errors'])) $lines[] = 'Never do: '    . implode('; ', $scientificModel['forbidden_errors']);
            $constraints = implode("\n", $lines);
        }

        // Step 2: HTML generation
        $htmlSystemPrompt = <<<PROMPT
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

        $keyPoints = implode("\n", array_map(fn($p, $i) => ($i+1).". $p", $outline['keyPoints'] ?? [], array_keys($outline['keyPoints'] ?? [])));
        $htmlUserPrompt = "Concept: {$config['conceptName']}\nSubject: " . ($config['subject'] ?? '') . "\nOverview: {$config['conceptOverview']}\nKey points:\n{$keyPoints}\n\nScientific constraints:\n{$constraints}\n\nDesign idea: {$config['designIdea']}\n\nOutput complete HTML only.";

        $htmlResponse = $this->llm->complete($htmlSystemPrompt, $htmlUserPrompt);
        // Extract HTML (LLM might wrap in code fences)
        $rawHtml = $this->extractHtml($htmlResponse);
        if (!$rawHtml) return null;

        $processedHtml = $this->htmlProcessor->process($rawHtml);
        if (!$processedHtml) return null; // validation failed

        return ['type' => 'interactive', 'html' => $processedHtml];
    }

    private function generateDiscussionContent(LessonScene $scene): array
    {
        $outline = $scene->outline_data ?? [];
        return [
            'type'             => 'discussion',
            'topic'            => $scene->title,
            'prompt'           => implode(' ', array_slice($outline['keyPoints'] ?? [], 0, 2)),
            'duration_seconds' => $scene->estimated_duration_seconds ?? 180,
        ];
    }

    // ── Stage 2B: Action sequence ────────────────────────────────────────

    private function generateActionSequence(LessonScene $scene, array $content): array
    {
        if ($scene->scene_type === 'interactive') {
            // Interactive scenes need no pre-generated actions
            return [];
        }

        $lesson  = $scene->lesson;
        $agents  = $lesson->agents()->where('is_active', true)->get();
        $teacher = $agents->firstWhere('role', 'teacher');

        if (!$teacher) return [];

        $systemPrompt = $this->actionSequenceSystemPrompt($scene->scene_type, $teacher);
        $userPrompt   = $this->actionSequenceUserPrompt($scene, $content);

        $response = $this->llm->complete($systemPrompt, $userPrompt, maxTokens: 2000);
        return $this->parseActionArray($response, $scene->scene_type);
    }

    private function actionSequenceSystemPrompt(string $sceneType, $teacher): string
    {
        $slideActions = $sceneType === 'slide'
            ? "- spotlight: Focus on a slide element. params: {elementId:string}\n- laser: Point at a slide element. params: {elementId:string}\n"
            : '';

        return <<<PROMPT
You are generating a teaching action sequence for a classroom agent.

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

    private function actionSequenceUserPrompt(LessonScene $scene, array $content): string
    {
        $outline   = $scene->outline_data ?? [];
        $keyPoints = implode("\n- ", $outline['keyPoints'] ?? []);

        if ($scene->scene_type === 'slide') {
            $elementSummary = collect($content['elements'] ?? [])
                ->map(fn($el) => "[id:{$el['id']}] {$el['type']}: " . substr(strip_tags($el['content'] ?? ''), 0, 50))
                ->implode("\n");
            return "Title: {$scene->title}\nKey points:\n- {$keyPoints}\n\nSlide elements:\n{$elementSummary}\n\nGenerate teaching action sequence JSON array.";
        }

        if ($scene->scene_type === 'quiz') {
            $questions = collect($content['questions'] ?? [])->map(fn($q, $i) => ($i+1).". [{$q['type']}] {$q['question']}")->implode("\n");
            return "Quiz title: {$scene->title}\nQuestions:\n{$questions}\n\nGenerate intro speech + discussion action. JSON array only.";
        }

        return "Scene: {$scene->title}\nKey points:\n- {$keyPoints}\n\nGenerate teaching action sequence JSON array.";
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function parseJsonArray(string $response): array
    {
        $response = trim($response);
        // Strip code fences
        $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
        $response = preg_replace('/\s*```\s*$/i', '', $response);

        $start = strpos($response, '[');
        $end   = strrpos($response, ']');
        if ($start === false || $end === false) return [];

        $json = substr($response, $start, $end - $start + 1);
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function parseJsonObject(string $response): array
    {
        $response = trim($response);
        $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
        $response = preg_replace('/\s*```\s*$/i', '', $response);

        $start = strpos($response, '{');
        $end   = strrpos($response, '}');
        if ($start === false || $end === false) return [];

        $json = substr($response, $start, $end - $start + 1);
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function parseActionArray(string $response, string $sceneType): array
    {
        $items    = $this->parseJsonArray($response);
        $actions  = [];
        $slideOnly = ['spotlight', 'laser'];

        foreach ($items as $item) {
            if (!isset($item['type'])) continue;

            if ($item['type'] === 'text') {
                $actions[] = [
                    'id'   => 'act_' . Str::random(8),
                    'type' => 'speech',
                    'text' => $item['content'] ?? '',
                ];
            } elseif ($item['type'] === 'action') {
                $name = $item['name'] ?? '';
                // Filter slide-only actions for non-slide scenes
                if ($sceneType !== 'slide' && in_array($name, $slideOnly)) continue;
                $actions[] = array_merge(
                    ['id' => 'act_' . Str::random(8), 'type' => $name],
                    $item['params'] ?? []
                );
            }
        }

        return $actions;
    }

    private function extractHtml(string $response): ?string
    {
        // Try to extract from code fence first
        if (preg_match('/```html\s*([\s\S]+?)\s*```/i', $response, $m)) {
            return trim($m[1]);
        }
        // Look for DOCTYPE or <html
        $pos = stripos($response, '<!DOCTYPE html');
        if ($pos !== false) return substr($response, $pos);
        $pos = stripos($response, '<html');
        if ($pos !== false) return substr($response, $pos);
        return null;
    }
}
```

---

## Step 6 — Director service

Create `app/Services/Classroom/DirectorService.php`:

```php
<?php
namespace App\Services\Classroom;

use App\Models\ClassroomSession;
use App\Models\LessonAgent;
use App\Services\AI\LLMService;

class DirectorService
{
    public function __construct(private LLMService $llm) {}

    /**
     * Decide which agent speaks next. Returns agent ID, 'USER', or null (END).
     */
    public function nextAgentId(ClassroomSession $session, array $agents): string|null
    {
        $activeAgents = collect($agents)->where('is_active', true)->values();

        if ($activeAgents->count() <= 1) {
            return $this->singleAgentDecision($session, $activeAgents->first());
        }

        return $this->multiAgentDecision($session, $activeAgents->all());
    }

    private function singleAgentDecision(ClassroomSession $session, ?LessonAgent $agent): ?string
    {
        if (!$agent) return null;
        // Turn 0: dispatch the agent. Turn 1+: cue the user.
        return $session->getDirectorTurnCount() === 0 ? $agent->id : 'USER';
    }

    private function multiAgentDecision(ClassroomSession $session, array $agents): ?string
    {
        // Fast path: first turn, dispatch teacher
        if ($session->getDirectorTurnCount() === 0) {
            $teacher = collect($agents)->firstWhere('role', 'teacher');
            return $teacher?->id;
        }

        $prompt = $this->buildDirectorPrompt($session, $agents);
        $response = $this->llm->complete(
            'You are the director of a multi-agent classroom. Output only a JSON object.',
            $prompt,
            maxTokens: 100,
        );

        return $this->parseDirectorDecision($response);
    }

    private function buildDirectorPrompt(ClassroomSession $session, array $agents): string
    {
        $agentList = collect($agents)->map(fn($a) =>
            "- id:\"{$a->id}\", name:\"{$a->display_name}\", role:{$a->role}, priority:{$a->priority}"
        )->implode("\n");

        $spoken = $session->getAgentsSpokenThisRound();
        $spokenList = empty($spoken) ? 'None yet.' : collect($spoken)->map(fn($s) =>
            "- {$s['name']} ({$s['agent_id']}): \"{$s['preview']}\" [{$s['action_count']} actions]"
        )->implode("\n");

        $wbLedger  = $session->getWhiteboardLedger();
        $wbCount   = count(array_filter($wbLedger, fn($r) => str_starts_with($r['action_name'] ?? '', 'wb_draw_')));
        $wbSection = $wbCount > 0
            ? "\n# Whiteboard\nElements on board: {$wbCount}" . ($wbCount > 5 ? "\n⚠ Crowded — prefer agents that organize rather than add." : '')
            : '';

        $turnCount = $session->getDirectorTurnCount();

        return <<<PROMPT
# Available agents
{$agentList}

# Already spoke this round
{$spokenList}
{$wbSection}

# Rules
1. Teacher (role:teacher) should usually speak first.
2. After teacher, a student agent can add a question or reaction.
3. Do NOT repeat an agent who already spoke this round.
4. Prefer brevity — 1-2 agents per round is enough.
5. Output USER to ask the student to respond.
6. Output END when topic is covered.
7. Role diversity: don't dispatch two teacher-role agents consecutively.
8. Current turn: {$turnCount}. Don't let discussions drag on.

# Output (JSON only)
{"next_agent":"<agent_id>"} or {"next_agent":"USER"} or {"next_agent":"END"}
PROMPT;
    }

    private function parseDirectorDecision(string $response): ?string
    {
        if (preg_match('/\{[^}]*"next_agent"\s*:\s*"([^"]+)"[^}]*\}/s', $response, $m)) {
            $val = trim($m[1]);
            if ($val === 'END') return null;
            return $val; // agent ID or 'USER'
        }
        return null; // default to END if unparseable
    }

    /**
     * Increment turn count and record this agent's contribution.
     */
    public function recordAgentTurn(
        ClassroomSession $session,
        string $agentId,
        string $agentName,
        string $contentPreview,
        int $actionCount,
        array $wbActions,
    ): void {
        $state = $session->director_state ?? [];

        $state['turn_count'] = ($state['turn_count'] ?? 0) + 1;
        $state['agents_spoken'][] = [
            'agent_id'     => $agentId,
            'name'         => $agentName,
            'preview'      => mb_substr($contentPreview, 0, 100),
            'action_count' => $actionCount,
        ];
        foreach ($wbActions as $action) {
            $state['whiteboard_ledger'][] = $action;
        }

        $session->update(['director_state' => $state]);
    }

    /**
     * Reset the round (agents_spoken list) when the student sends a new message.
     */
    public function startNewRound(ClassroomSession $session): void
    {
        $state = $session->director_state ?? [];
        $state['agents_spoken'] = [];
        $session->update(['director_state' => $state]);
    }
}
```

---

## Step 7 — Whiteboard service

Create `app/Services/Classroom/WhiteboardService.php`:

```php
<?php
namespace App\Services\Classroom;

use App\Models\ClassroomSession;
use App\Models\WhiteboardSnapshot;
use Illuminate\Support\Str;

class WhiteboardService
{
    const CANVAS_WIDTH  = 1000;
    const CANVAS_HEIGHT = 562;

    public function applyAction(ClassroomSession $session, array $action): void
    {
        $elements = $session->whiteboard_elements ?? [];
        $type     = $action['type'] ?? '';

        match($type) {
            'wb_open'  => $this->open($session),
            'wb_close' => $this->close($session),
            'wb_clear' => $this->clear($session),
            'wb_delete'=> $this->delete($session, $action['elementId'] ?? ''),
            default    => $this->addElement($session, $action),
        };
    }

    private function open(ClassroomSession $session): void
    {
        $session->update(['whiteboard_open' => true]);
    }

    private function close(ClassroomSession $session): void
    {
        $session->update(['whiteboard_open' => false]);
    }

    private function clear(ClassroomSession $session): void
    {
        $session->update(['whiteboard_elements' => [], 'whiteboard_open' => true]);
    }

    private function delete(ClassroomSession $session, string $elementId): void
    {
        $elements = $session->whiteboard_elements ?? [];
        $elements = array_values(array_filter($elements, fn($el) => ($el['id'] ?? '') !== $elementId));
        $session->update(['whiteboard_elements' => $elements]);
    }

    private function addElement(ClassroomSession $session, array $action): void
    {
        $type = $action['type'] ?? '';
        if (!str_starts_with($type, 'wb_draw_')) return;

        $element = $this->buildElement($action);
        if (!$element) return;

        $elements   = $session->whiteboard_elements ?? [];
        $elements[] = $element;
        $session->update(['whiteboard_elements' => $elements, 'whiteboard_open' => true]);
    }

    private function buildElement(array $action): ?array
    {
        $type = $action['type'] ?? '';
        $id   = $action['elementId'] ?? 'wb_' . Str::random(8);

        $base = [
            'id'        => $id,
            'added_by'  => $action['_agent_id'] ?? 'unknown',
            'added_at'  => now()->timestamp,
        ];

        return match($type) {
            'wb_draw_text' => array_merge($base, [
                'type'    => 'text',
                'left'    => $this->clampX($action['x'] ?? 100),
                'top'     => $this->clampY($action['y'] ?? 100),
                'width'   => min($action['width']  ?? 400, self::CANVAS_WIDTH),
                'height'  => min($action['height'] ?? 100, self::CANVAS_HEIGHT),
                'content' => '<p style="font-size:' . ($action['fontSize'] ?? 18) . 'px">' . htmlspecialchars($action['content'] ?? '') . '</p>',
                'color'   => $action['color'] ?? '#333333',
            ]),
            'wb_draw_shape' => array_merge($base, [
                'type'       => 'shape',
                'left'       => $this->clampX($action['x'] ?? 200),
                'top'        => $this->clampY($action['y'] ?? 150),
                'width'      => min($action['width']  ?? 200, self::CANVAS_WIDTH),
                'height'     => min($action['height'] ?? 100, self::CANVAS_HEIGHT),
                'shape'      => in_array($action['shape'] ?? '', ['rectangle','circle','triangle'])
                                    ? $action['shape'] : 'rectangle',
                'fill_color' => $action['fillColor'] ?? '#5b9bd5',
            ]),
            'wb_draw_latex' => array_merge($base, [
                'type'   => 'latex',
                'left'   => $this->clampX($action['x'] ?? 100),
                'top'    => $this->clampY($action['y'] ?? 100),
                'width'  => min($action['width']  ?? 400, self::CANVAS_WIDTH),
                'height' => min($action['height'] ?? 80, self::CANVAS_HEIGHT),
                'latex'  => $action['latex'] ?? '',
                'color'  => $action['color'] ?? '#000000',
            ]),
            'wb_draw_chart' => array_merge($base, [
                'type'       => 'chart',
                'left'       => $this->clampX($action['x'] ?? 100),
                'top'        => $this->clampY($action['y'] ?? 100),
                'width'      => min($action['width']  ?? 400, self::CANVAS_WIDTH),
                'height'     => min($action['height'] ?? 250, self::CANVAS_HEIGHT),
                'chart_type' => $action['chartType'] ?? 'bar',
                'data'       => $action['data'] ?? [],
            ]),
            'wb_draw_table' => array_merge($base, [
                'type'   => 'table',
                'left'   => $this->clampX($action['x'] ?? 100),
                'top'    => $this->clampY($action['y'] ?? 100),
                'width'  => min($action['width']  ?? 500, self::CANVAS_WIDTH),
                'height' => min($action['height'] ?? 160, self::CANVAS_HEIGHT),
                'data'   => $action['data'] ?? [['Header']],
            ]),
            'wb_draw_line' => array_merge($base, [
                'type'    => 'line',
                'start_x' => $this->clampX($action['startX'] ?? 0),
                'start_y' => $this->clampY($action['startY'] ?? 0),
                'end_x'   => $this->clampX($action['endX'] ?? 100),
                'end_y'   => $this->clampY($action['endY'] ?? 100),
                'color'   => $action['color'] ?? '#333333',
                'width'   => min((int)($action['width'] ?? 2), 10),
                'style'   => in_array($action['style'] ?? '', ['solid','dashed']) ? $action['style'] : 'solid',
                'points'  => $action['points'] ?? ['',''],
            ]),
            default => null,
        };
    }

    public function getState(ClassroomSession $session): array
    {
        return [
            'elements' => $session->whiteboard_elements ?? [],
            'open'     => $session->whiteboard_open,
        ];
    }

    public function snapshot(ClassroomSession $session, string $sceneId): void
    {
        WhiteboardSnapshot::create([
            'session_id' => $session->id,
            'scene_id'   => $sceneId,
            'elements'   => $session->whiteboard_elements ?? [],
        ]);
    }

    private function clampX(int|float $x): int
    {
        return (int) max(0, min($x, self::CANVAS_WIDTH));
    }

    private function clampY(int|float $y): int
    {
        return (int) max(0, min($y, self::CANVAS_HEIGHT));
    }
}
```

---

## Step 8 — Agent orchestrator (streaming JSON array parser)

Create `app/Services/Classroom/AgentOrchestrator.php`:

```php
<?php
namespace App\Services\Classroom;

use App\Models\ClassroomSession;
use App\Models\LessonAgent;
use App\Services\AI\LLMService;
use App\Services\AI\SafetyFilter;
use Generator;

class AgentOrchestrator
{
    public function __construct(
        private LLMService     $llm,
        private WhiteboardService $whiteboard,
        private SafetyFilter   $safety,
    ) {}

    /**
     * Generate one agent's turn, yielding SSE events incrementally.
     *
     * @return Generator<array> — yields SSE event arrays
     */
    public function generateTurn(
        ClassroomSession $session,
        LessonAgent $agent,
        string $studentMessage,
    ): Generator {
        $scene     = $session->currentScene;
        $sceneType = $scene?->scene_type ?? 'slide';

        yield ['type' => 'agent_start', 'data' => [
            'agentId'    => $agent->id,
            'agentName'  => $agent->display_name,
            'agentColor' => $agent->color_hex,
            'agentEmoji' => $agent->avatar_emoji,
        ]];

        $systemPrompt = $this->buildSystemPrompt($session, $agent, $sceneType);
        $messages     = $this->buildMessages($session, $studentMessage);

        $buffer           = '';
        $jsonStarted      = false;
        $parsedCount      = 0;
        $partialTextLen   = 0;
        $contentPreview   = '';
        $actionCount      = 0;
        $wbActions        = [];
        $allText          = '';

        // Stream from LLM
        foreach ($this->llm->stream($systemPrompt, $messages) as $chunk) {
            $buffer .= $chunk;

            if (!$jsonStarted) {
                $pos = strpos($buffer, '[');
                if ($pos === false) continue;
                $buffer      = substr($buffer, $pos);
                $jsonStarted = true;
            }

            // Attempt incremental parse
            $trimmed   = rtrim($buffer);
            $arrayDone = str_ends_with($trimmed, ']') && strlen($trimmed) > 1;

            $json = @json_decode($buffer, true);
            if (!is_array($json)) {
                // Try repairing (handles trailing comma, unescaped quotes)
                $repaired = $this->repairJson($buffer);
                $json     = @json_decode($repaired, true);
            }
            if (!is_array($json)) continue;

            $completeUpTo = $arrayDone ? count($json) : max(0, count($json) - 1);

            for ($i = $parsedCount; $i < $completeUpTo; $i++) {
                $item = $json[$i] ?? null;
                if (!$item || !isset($item['type'])) continue;

                if ($item['type'] === 'text') {
                    $text = $item['content'] ?? '';
                    // Emit only the new portion (delta from partial)
                    $newText = mb_substr($text, $partialTextLen);
                    if ($newText !== '') {
                        // Safety check on complete text items
                        $flag = $this->safety->check($text);
                        if ($flag->flagged && in_array($flag->severity, ['critical','high'])) {
                            yield ['type' => 'text_delta', 'data' => ['content' => 'Let me rephrase that.']];
                        } else {
                            yield ['type' => 'text_delta', 'data' => ['content' => $newText]];
                            $allText .= $newText;
                        }
                    }
                    $partialTextLen = 0;
                } elseif ($item['type'] === 'action') {
                    $name   = $item['name'] ?? '';
                    $params = $item['params'] ?? [];

                    // Apply whiteboard side effects server-side
                    if (str_starts_with($name, 'wb_')) {
                        $this->whiteboard->applyAction($session, array_merge(
                            ['type' => $name, '_agent_id' => $agent->id],
                            $params,
                        ));
                        $wbActions[] = ['action_name' => $name, 'agent_id' => $agent->id, 'agent_name' => $agent->display_name, 'params' => $params];
                    }

                    yield ['type' => 'action', 'data' => [
                        'actionName' => $name,
                        'params'     => $params,
                        'agentId'    => $agent->id,
                    ]];
                    $actionCount++;
                }
            }

            // Stream partial text delta for trailing incomplete text item
            if (!$arrayDone && count($json) > $completeUpTo) {
                $last = $json[count($json) - 1] ?? null;
                if ($last && ($last['type'] ?? '') === 'text') {
                    $text    = $last['content'] ?? '';
                    $newPart = mb_substr($text, $partialTextLen);
                    if ($newPart !== '') {
                        yield ['type' => 'text_delta', 'data' => ['content' => $newPart]];
                        $partialTextLen = mb_strlen($text);
                        $allText .= $newPart;
                    }
                }
            }

            $parsedCount = $completeUpTo;

            if ($arrayDone) break;
        }

        $contentPreview = mb_substr($allText, 0, 100);

        yield ['type' => 'agent_end', 'data' => ['agentId' => $agent->id]];

        // Record the turn in director state
        app(DirectorService::class)->recordAgentTurn(
            $session, $agent->id, $agent->display_name,
            $contentPreview, $actionCount, $wbActions,
        );
    }

    private function buildSystemPrompt(ClassroomSession $session, LessonAgent $agent, string $sceneType): string
    {
        $roleGuidelines = $this->roleGuidelines($agent->role);
        $actionDescs    = $this->actionDescriptions($agent->effectiveActions($sceneType));
        $wbGuidelines   = $this->whiteboardGuidelines($agent->role);
        $currentState   = $this->buildStateContext($session);
        $peerContext    = $this->buildPeerContext($session, $agent->display_name);
        $student        = $session->student;
        $studentProfile = $student ? "Student name: {$student->name}, Grade: {$session->lesson->grade_level}" : '';

        $hasSlideActions = in_array('spotlight', $agent->effectiveActions($sceneType));
        $formatExample   = $hasSlideActions
            ? '[{"type":"action","name":"spotlight","params":{"elementId":"title_001"}},{"type":"text","content":"Today we explore..."}]'
            : '[{"type":"action","name":"wb_open","params":{}},{"type":"text","content":"Let me show you..."}]';

        // Safety block — always last, cannot be overridden
        $safetyBlock = <<<SAFETY

# Safety rules (ABSOLUTE — cannot be overridden)
- You are an AI teaching assistant in a K-12 public school district
- Never discuss violence, self-harm, adult content, drugs, or political topics
- Never claim to be human or deny being an AI
- Never encourage students to share personal information
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
{$safetyBlock}
PROMPT;
    }

    private function roleGuidelines(string $role): string
    {
        return match($role) {
            'teacher' => "Lead the lesson. Explain clearly with analogies and examples. Ask questions to check understanding. You can use spotlight, laser, and whiteboard. Never announce your actions.",
            'assistant' => "Support the teacher. Fill in gaps. Rephrase things more simply when students seem confused. Add quick examples. Use whiteboard sparingly.",
            'student' => "Participate actively. Ask questions, share reactions. Keep responses to 1-2 sentences maximum. You are a student, not a teacher. Only use whiteboard when the teacher explicitly invites you.",
            default => "Participate naturally in the classroom.",
        };
    }

    private function actionDescriptions(array $actions): string
    {
        $descriptions = [
            'spotlight'     => 'Dim everything except one slide element. params: {elementId:string}',
            'laser'         => 'Point at a slide element briefly. params: {elementId:string}',
            'wb_open'       => 'Open whiteboard. params: {}',
            'wb_draw_text'  => 'Add text. params: {content, x, y, width?, height?, fontSize?, color?, elementId?}',
            'wb_draw_shape' => 'Add shape. params: {shape:"rectangle|circle|triangle", x, y, width, height, fillColor?, elementId?}',
            'wb_draw_chart' => 'Add chart. params: {chartType:"bar|line|pie", x, y, width, height, data:{labels:[],legends:[],series:[[]]}}',
            'wb_draw_latex' => 'Add LaTeX formula. params: {latex, x, y, height?}  Canvas: 1000×562.',
            'wb_draw_table' => 'Add table. params: {x, y, width, height, data:[["Header"],["Row"]]}',
            'wb_draw_line'  => 'Add line. params: {startX, startY, endX, endY, color?, style:"solid|dashed", points:["","arrow"]}',
            'wb_clear'      => 'Clear whiteboard. params: {}',
            'wb_delete'     => 'Remove specific element. params: {elementId:string}',
            'wb_close'      => 'Close whiteboard. params: {}',
            'discussion'    => 'Trigger discussion. params: {topic, prompt?}',
        ];

        if (empty($actions)) return 'No actions available. Speak only.';

        return collect($actions)
            ->filter(fn($a) => isset($descriptions[$a]))
            ->map(fn($a) => "- {$a}: {$descriptions[$a]}")
            ->implode("\n");
    }

    private function whiteboardGuidelines(string $role): string
    {
        if ($role === 'student') {
            return "ONLY use the whiteboard when the teacher explicitly invites you (e.g. 'come solve this on the board'). Never proactively draw on the whiteboard.";
        }
        return "Canvas is 1000×562. Positions in this coordinate space. Ensure x+width≤1000, y+height≤562. Leave 20px gap between elements. Call wb_clear when board is crowded before adding new elements. Do NOT call wb_close at the end — leave whiteboard open for students to read.";
    }

    private function buildStateContext(ClassroomSession $session): string
    {
        $scene    = $session->currentScene;
        $elements = $session->whiteboard_elements ?? [];
        $wbOpen   = $session->whiteboard_open;
        $lines    = [];

        $lines[] = "Whiteboard: " . ($wbOpen ? 'OPEN (slide canvas is hidden)' : 'closed');
        $lines[] = "Whiteboard elements: " . count($elements);

        if ($scene) {
            $lines[] = "Current scene: \"{$scene->title}\" (type: {$scene->scene_type})";
            if ($scene->scene_type === 'slide') {
                $els = $scene->content['elements'] ?? [];
                $summary = collect($els)->map(fn($el) => "[id:{$el['id']}] {$el['type']}: " . mb_substr(strip_tags($el['content'] ?? ''), 0, 40))->implode(', ');
                $lines[] = "Slide elements: {$summary}";
            }
            if ($scene->scene_type === 'quiz') {
                $qs = collect($scene->content['questions'] ?? [])
                    ->map(fn($q, $i) => ($i+1).". {$q['question']}")
                    ->implode('; ');
                $lines[] = "Quiz questions: {$qs}";
            }
        }

        return implode("\n", $lines);
    }

    private function buildPeerContext(ClassroomSession $session, string $currentAgentName): string
    {
        $spoken = $session->getAgentsSpokenThisRound();
        $peers  = array_filter($spoken, fn($s) => $s['name'] !== $currentAgentName);
        if (empty($peers)) return '';

        $lines = ["# What others already said this round (do NOT repeat):"];
        foreach ($peers as $peer) {
            $lines[] = "- {$peer['name']}: \"{$peer['preview']}\"";
        }
        $lines[] = "Build on or question what was said. Do not repeat greetings.";
        return implode("\n", $lines);
    }

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
                $messages[] = ['role' => 'user', 'content' => $msg->content_text];
            } elseif ($msg->sender_type === 'agent') {
                $messages[] = ['role' => 'assistant', 'content' => $msg->content_text ?? ''];
            }
        }

        if ($studentMessage) {
            $messages[] = ['role' => 'user', 'content' => $studentMessage];
        }

        return $messages;
    }

    private function repairJson(string $json): string
    {
        // Remove trailing comma before ] or }
        $json = preg_replace('/,\s*([\]}])/', '$1', $json);
        // Close unclosed array if needed
        $opens  = substr_count($json, '[');
        $closes = substr_count($json, ']');
        if ($opens > $closes) $json .= str_repeat(']', $opens - $closes);
        return $json;
    }
}
```

---

## Step 9 — Quiz grader service

Create `app/Services/Classroom/QuizGraderService.php`:

```php
<?php
namespace App\Services\Classroom;

use App\Models\LessonQuizAttempt;
use App\Services\AI\LLMService;

class QuizGraderService
{
    public function __construct(private LLMService $llm) {}

    /**
     * Grade a quiz attempt. Returns ['is_correct', 'score', 'feedback', 'analysis'].
     */
    public function grade(LessonQuizAttempt $attempt, array $question): array
    {
        $type = $question['type'] ?? 'single';

        if (in_array($type, ['single', 'multiple'])) {
            return $this->gradeMultipleChoice($attempt, $question);
        }

        return $this->gradeShortAnswer($attempt, $question);
    }

    private function gradeMultipleChoice(LessonQuizAttempt $attempt, array $question): array
    {
        $correct  = array_map('strtoupper', (array)($question['answer'] ?? []));
        $given    = array_map('strtoupper', (array)($attempt->student_answer ?? []));
        sort($correct);
        sort($given);

        $isCorrect = $correct === $given;
        $maxScore  = $question['points'] ?? 10;
        $score     = $isCorrect ? $maxScore : 0;
        $analysis  = $question['analysis'] ?? ($isCorrect ? 'Correct!' : 'Not quite. Review the material and try again.');

        return [
            'is_correct' => $isCorrect,
            'score'      => $score,
            'max_score'  => $maxScore,
            'feedback'   => $analysis,
        ];
    }

    private function gradeShortAnswer(LessonQuizAttempt $attempt, array $question): array
    {
        $maxScore     = $question['points'] ?? 10;
        $studentAnswer = is_array($attempt->student_answer)
            ? implode(' ', $attempt->student_answer)
            : (string)($attempt->student_answer ?? '');

        $systemPrompt = "You are a K-12 educational assessor. Grade the student's answer.\nReply in JSON only: {\"score\": <integer 0-{$maxScore}>, \"comment\": \"<1-2 sentences of feedback>\"}";

        $commentPrompt = $question['commentPrompt'] ?? '';
        $userPrompt    = "Question: {$question['question']}\nFull marks: {$maxScore} points"
            . ($commentPrompt ? "\nGrading guidance: {$commentPrompt}" : '')
            . "\nStudent answer: {$studentAnswer}";

        $response = $this->llm->complete($systemPrompt, $userPrompt, maxTokens: 200);

        // Parse response
        if (preg_match('/\{[^}]+\}/s', $response, $m)) {
            $parsed = json_decode($m[0], true);
            if ($parsed) {
                $score     = (int) max(0, min($maxScore, $parsed['score'] ?? 0));
                $comment   = $parsed['comment'] ?? 'Answer received.';
                $isCorrect = $score >= ($maxScore * 0.6);
                return ['is_correct' => $isCorrect, 'score' => $score, 'max_score' => $maxScore, 'feedback' => $comment];
            }
        }

        // Fallback: 50%
        return ['is_correct' => false, 'score' => $maxScore * 0.5, 'max_score' => $maxScore, 'feedback' => 'Answer received. Please review the correct approach.'];
    }
}
```

---

## Step 10 — Horizon jobs

### `app/Jobs/GenerateLessonOutlineJob.php`
```php
<?php
namespace App\Jobs;

use App\Models\ClassroomLesson;
use App\Services\Classroom\LessonGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateLessonOutlineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 2;

    public function __construct(public string $lessonId) {}

    public function handle(LessonGeneratorService $generator): void
    {
        $lesson = ClassroomLesson::findOrFail($this->lessonId);
        $generator->generateOutline($lesson);

        // Dispatch one scene job per scene
        foreach ($lesson->scenes as $scene) {
            GenerateSceneContentJob::dispatch($scene->id)->onQueue('default');
        }
    }

    public function failed(\Throwable $e): void
    {
        ClassroomLesson::find($this->lessonId)?->update([
            'generation_status'   => 'failed',
            'generation_progress' => ['message' => $e->getMessage()],
        ]);
    }
}
```

### `app/Jobs/GenerateSceneContentJob.php`
```php
<?php
namespace App\Jobs;

use App\Models\LessonScene;
use App\Models\ClassroomLesson;
use App\Services\Classroom\LessonGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateSceneContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries   = 2;

    public function __construct(public string $sceneId) {}

    public function handle(LessonGeneratorService $generator): void
    {
        $scene = LessonScene::findOrFail($this->sceneId);
        $generator->generateSceneContent($scene);

        // Check if all scenes are now ready
        $lesson      = $scene->lesson;
        $pending     = $lesson->scenes()->whereIn('generation_status', ['pending','generating'])->count();
        $failed      = $lesson->scenes()->where('generation_status', 'error')->count();
        $totalScenes = $lesson->scenes()->count();
        $ready       = $lesson->scenes()->where('generation_status', 'ready')->count();

        $lesson->update([
            'generation_progress' => [
                'step'             => 'generating_scenes',
                'progress'         => (int)(30 + ($ready / max(1, $totalScenes)) * 60),
                'total_scenes'     => $totalScenes,
                'scenes_generated' => $ready,
            ],
        ]);

        if ($pending === 0) {
            $lesson->update([
                'generation_status'   => $failed > 0 && $ready === 0 ? 'failed' : 'completed',
                'generation_progress' => [
                    'step'             => 'completed',
                    'progress'         => 100,
                    'total_scenes'     => $totalScenes,
                    'scenes_generated' => $ready,
                ],
            ]);

            // Notify teacher via Reverb
            broadcast(new \App\Events\LessonGenerationCompleted($lesson));
        }
    }
}
```

Add `LessonGenerationCompleted` event in `app/Events/` following the existing event pattern from Phase 5.

---

## Step 11 — API controllers

### `app/Http/Controllers/Teacher/LessonController.php`
```php
<?php
namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\ClassroomLesson;
use App\Models\LessonAgent;
use App\Services\Classroom\AgentArchetypes;
use App\Jobs\GenerateLessonOutlineJob;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class LessonController extends Controller
{
    public function index(Request $request)
    {
        $lessons = ClassroomLesson::where('teacher_id', auth()->id())
            ->with(['scenes' => fn($q) => $q->select('id','lesson_id','sequence_order','scene_type','title','generation_status')])
            ->latest()->paginate(20);

        return Inertia::render('Teacher/Lessons/Index', ['lessons' => $lessons]);
    }

    public function create()
    {
        return Inertia::render('Teacher/Lessons/Create', [
            'archetypes' => AgentArchetypes::ARCHETYPES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'          => 'required|string|max:200',
            'source_type'    => 'required|in:topic,pdf,standard',
            'source_text'    => 'required_if:source_type,topic|nullable|string|max:5000',
            'subject'        => 'nullable|string|max:100',
            'grade_level'    => 'nullable|string|max:10',
            'language'       => 'nullable|in:en,es,fr',
            'agent_mode'     => 'in:default,custom',
            'agents'         => 'nullable|array',
            'agents.*.archetype' => 'in:teacher,assistant,curious,notetaker,skeptic,enthusiast',
            'agents.*.display_name' => 'nullable|string|max:50',
        ]);

        $lesson = ClassroomLesson::create([
            'district_id'       => auth()->user()->district_id,
            'teacher_id'        => auth()->id(),
            'title'             => $data['title'],
            'source_type'       => $data['source_type'],
            'source_text'       => $data['source_text'] ?? null,
            'subject'           => $data['subject'] ?? null,
            'grade_level'       => $data['grade_level'] ?? null,
            'language'          => $data['language'] ?? 'en',
            'agent_mode'        => $data['agent_mode'] ?? 'default',
            'generation_status' => 'pending',
        ]);

        // Create agents
        $archetypes = $data['agents']
            ?? array_map(fn($a) => ['archetype' => $a], AgentArchetypes::defaultAgentsForLesson($data['grade_level'] ?? ''));

        foreach ($archetypes as $i => $agentData) {
            $archetype = AgentArchetypes::get($agentData['archetype']);
            LessonAgent::create([
                'lesson_id'      => $lesson->id,
                'district_id'    => $lesson->district_id,
                'role'           => $archetype['role'],
                'display_name'   => $agentData['display_name'] ?? $archetype['display_name'],
                'archetype'      => $agentData['archetype'],
                'avatar_emoji'   => $archetype['avatar_emoji'],
                'color_hex'      => $archetype['color_hex'],
                'persona_text'   => $archetype['persona_text'],
                'allowed_actions'=> AgentArchetypes::ROLE_ACTIONS[$archetype['role']],
                'priority'       => $archetype['priority'],
                'sequence_order' => $i,
                'is_active'      => true,
            ]);
        }

        // Dispatch generation
        GenerateLessonOutlineJob::dispatch($lesson->id)->onQueue('default');

        return redirect()->route('teach.lessons.show', $lesson)->with('success', 'Lesson generation started.');
    }

    public function show(ClassroomLesson $lesson)
    {
        $this->authorize('view', $lesson);
        $lesson->load(['scenes', 'agents' => fn($q) => $q->orderBy('sequence_order')]);

        return Inertia::render('Teacher/Lessons/Show', [
            'lesson' => $lesson,
        ]);
    }

    public function status(ClassroomLesson $lesson)
    {
        $this->authorize('view', $lesson);
        $scenes = $lesson->scenes()->select('id','scene_type','title','generation_status','sequence_order')->get();

        return response()->json([
            'generation_status'   => $lesson->generation_status,
            'generation_progress' => $lesson->generation_progress,
            'scenes'              => $scenes,
        ]);
    }

    public function publish(Request $request, ClassroomLesson $lesson)
    {
        $this->authorize('update', $lesson);
        $data = $request->validate(['space_id' => 'required|uuid']);

        $lesson->update([
            'space_id'     => $data['space_id'],
            'status'       => 'published',
            'published_at' => now(),
        ]);

        return back()->with('success', 'Lesson published to space.');
    }
}
```

### `app/Http/Controllers/Student/ClassroomController.php`
```php
<?php
namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\ClassroomLesson;
use App\Models\ClassroomSession;
use App\Models\ClassroomMessage;
use App\Models\LessonQuizAttempt;
use App\Services\Classroom\DirectorService;
use App\Services\Classroom\AgentOrchestrator;
use App\Services\Classroom\WhiteboardService;
use App\Services\Classroom\QuizGraderService;
use App\Services\AI\SafetyFilter;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClassroomController extends Controller
{
    public function __construct(
        private DirectorService    $director,
        private AgentOrchestrator  $orchestrator,
        private WhiteboardService  $whiteboard,
        private QuizGraderService  $grader,
        private SafetyFilter       $safety,
    ) {}

    public function start(Request $request, string $spaceId)
    {
        $space  = \App\Models\LearningSpace::findOrFail($spaceId);
        $lesson = ClassroomLesson::where('space_id', $spaceId)
            ->where('status', 'published')
            ->where('generation_status', 'completed')
            ->firstOrFail();

        $session = ClassroomSession::create([
            'district_id'    => auth()->user()->district_id,
            'lesson_id'      => $lesson->id,
            'student_id'     => auth()->id(),
            'current_scene_id' => $lesson->scenes()->orderBy('sequence_order')->first()?->id,
            'director_state' => ['turn_count' => 0, 'agents_spoken' => [], 'whiteboard_ledger' => []],
            'whiteboard_elements' => [],
            'whiteboard_open' => false,
            'status'         => 'active',
        ]);

        return redirect()->route('learn.classroom', $session->id);
    }

    public function show(ClassroomSession $session)
    {
        $this->authorize('view', $session);
        $session->load(['lesson.agents', 'currentScene', 'lesson.scenes']);

        return Inertia::render('Student/Classroom', [
            'session' => $session,
            'lesson'  => $session->lesson,
            'agents'  => $session->lesson->agents->where('is_active', true)->values(),
        ]);
    }

    public function message(Request $request, ClassroomSession $session)
    {
        $this->authorize('update', $session);

        $data = $request->validate([
            'content' => 'required|string|max:2000',
        ]);

        $studentMessage = $data['content'];

        // Safety check on student message
        $flag = $this->safety->check($studentMessage);
        if ($flag->flagged && in_array($flag->severity, ['critical','high'])) {
            // Log and return generic response
            return response()->stream(function () {
                echo "data: " . json_encode(['type' => 'text_delta', 'data' => ['content' => "Let's keep our conversation focused on the lesson. Can you rephrase your question?"]]) . "\n\n";
                echo "data: " . json_encode(['type' => 'done', 'data' => []]) . "\n\n";
                ob_flush(); flush();
            }, 200, $this->sseHeaders());
        }

        // Store student message
        ClassroomMessage::create([
            'session_id'   => $session->id,
            'district_id'  => $session->district_id,
            'sender_type'  => 'student',
            'content_text' => $studentMessage,
        ]);

        // New round: reset agents_spoken
        $this->director->startNewRound($session);

        $agents  = $session->lesson->agents()->where('is_active', true)->orderBy('priority', 'desc')->get();
        $maxTurns = min(count($agents), 3);

        return response()->stream(function () use ($session, $agents, $studentMessage, $maxTurns) {
            $turnsThisRequest = 0;

            while ($turnsThisRequest < $maxTurns) {
                // Refresh session from DB
                $session->refresh();

                $nextId = $this->director->nextAgentId($session, $agents->all());

                if ($nextId === null) {
                    // END
                    break;
                }

                if ($nextId === 'USER') {
                    $this->sendSseEvent(['type' => 'cue_user', 'data' => ['prompt' => 'What do you think?']]);
                    break;
                }

                $agent = $agents->firstWhere('id', $nextId);
                if (!$agent) break;

                $this->sendSseEvent(['type' => 'thinking', 'data' => ['stage' => 'agent_loading', 'agentId' => $agent->id]]);

                $allText   = '';
                $allActions = [];

                foreach ($this->orchestrator->generateTurn($session, $agent, $studentMessage) as $event) {
                    $this->sendSseEvent($event);
                    if ($event['type'] === 'text_delta') $allText .= $event['data']['content'];
                    if ($event['type'] === 'action') $allActions[] = $event['data'];
                }

                // Persist agent message
                ClassroomMessage::create([
                    'session_id'   => $session->id,
                    'district_id'  => $session->district_id,
                    'sender_type'  => 'agent',
                    'agent_id'     => $agent->id,
                    'content_text' => $allText,
                    'actions_json' => $allActions,
                ]);

                $turnsThisRequest++;
                $session->refresh();
            }

            $this->sendSseEvent(['type' => 'done', 'data' => ['totalAgents' => $turnsThisRequest]]);
        }, 200, $this->sseHeaders());
    }

    public function whiteboard(ClassroomSession $session)
    {
        $this->authorize('view', $session);
        return response()->json($this->whiteboard->getState($session));
    }

    public function submitQuiz(Request $request, ClassroomSession $session, string $sceneId)
    {
        $this->authorize('update', $session);
        $scene = $session->lesson->scenes()->findOrFail($sceneId);
        $data  = $request->validate([
            'question_index' => 'required|integer',
            'answer'         => 'required',
        ]);

        $questions = $scene->content['questions'] ?? [];
        $question  = $questions[$data['question_index']] ?? null;
        if (!$question) return response()->json(['error' => 'Question not found'], 404);

        $attempt = LessonQuizAttempt::create([
            'session_id'     => $session->id,
            'district_id'    => $session->district_id,
            'scene_id'       => $scene->id,
            'question_index' => $data['question_index'],
            'question_type'  => $question['type'],
            'student_answer' => (array)$data['answer'],
            'max_score'      => $question['points'] ?? 10,
        ]);

        $result = $this->grader->grade($attempt, $question);

        $attempt->update([
            'is_correct' => $result['is_correct'],
            'score'      => $result['score'],
            'llm_feedback'=> $result['feedback'],
            'graded_at'  => now(),
        ]);

        return response()->json([
            'is_correct' => $result['is_correct'],
            'score'      => $result['score'],
            'max_score'  => $result['max_score'],
            'feedback'   => $result['feedback'],
            'analysis'   => $question['analysis'] ?? null,
        ]);
    }

    public function end(ClassroomSession $session)
    {
        $this->authorize('update', $session);
        $this->whiteboard->snapshot($session, $session->current_scene_id ?? '');
        $session->update(['status' => 'completed', 'ended_at' => now()]);
        return redirect()->route('learn.spaces.show', $session->lesson->space_id);
    }

    private function sendSseEvent(array $event): void
    {
        echo "data: " . json_encode($event) . "\n\n";
        ob_flush();
        flush();
    }

    private function sseHeaders(): array
    {
        return [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ];
    }
}
```

---

## Step 12 — Routes

Add to `routes/web.php`:

```php
// Teacher — Lesson management
Route::middleware(['auth', 'role:teacher|school_admin|district_admin'])->prefix('teach')->name('teach.')->group(function () {
    Route::get('/lessons',              [Teacher\LessonController::class, 'index'])->name('lessons.index');
    Route::get('/lessons/create',       [Teacher\LessonController::class, 'create'])->name('lessons.create');
    Route::post('/lessons',             [Teacher\LessonController::class, 'store'])->name('lessons.store');
    Route::get('/lessons/{lesson}',     [Teacher\LessonController::class, 'show'])->name('lessons.show');
    Route::get('/lessons/{lesson}/status', [Teacher\LessonController::class, 'status'])->name('lessons.status');
    Route::post('/lessons/{lesson}/publish', [Teacher\LessonController::class, 'publish'])->name('lessons.publish');
});

// Student — Classroom sessions
Route::middleware(['auth', 'role:student'])->prefix('learn')->name('learn.')->group(function () {
    Route::post('/spaces/{space}/classroom', [Student\ClassroomController::class, 'start'])->name('classroom.start');
    Route::get('/classroom/{session}',       [Student\ClassroomController::class, 'show'])->name('classroom');
    Route::post('/classroom/{session}/message', [Student\ClassroomController::class, 'message'])->name('classroom.message');
    Route::get('/classroom/{session}/whiteboard', [Student\ClassroomController::class, 'whiteboard'])->name('classroom.whiteboard');
    Route::post('/classroom/{session}/quiz/{scene}', [Student\ClassroomController::class, 'submitQuiz'])->name('classroom.quiz');
    Route::post('/classroom/{session}/end', [Student\ClassroomController::class, 'end'])->name('classroom.end');
});
```

---

## Step 13 — Frontend: Teacher lesson builder

### `resources/js/pages/Teacher/Lessons/Create.tsx`

```tsx
import { useState } from 'react'
import { router } from '@inertiajs/react'
import TeacherLayout from '@/layouts/TeacherLayout'

const ARCHETYPES = [
  { key: 'teacher',    emoji: '👩‍🏫', label: 'Teacher',           role: 'teacher',    required: true },
  { key: 'assistant',  emoji: '🤝',  label: 'Teaching Assistant', role: 'assistant',  required: false },
  { key: 'curious',    emoji: '🤔',  label: 'Sam (Curious)',      role: 'student',    required: false },
  { key: 'notetaker',  emoji: '📝',  label: 'Alex (Note-taker)',  role: 'student',    required: false },
  { key: 'skeptic',    emoji: '🧐',  label: 'Jordan (Skeptic)',   role: 'student',    required: false },
  { key: 'enthusiast', emoji: '🌟',  label: 'Riley (Enthusiast)', role: 'student',    required: false },
]

export default function CreateLesson() {
  const [form, setForm] = useState({
    title: '', source_type: 'topic', source_text: '',
    subject: '', grade_level: '', language: 'en',
    agents: ['teacher', 'curious'], // default selection
  })
  const [submitting, setSubmitting] = useState(false)

  const toggleAgent = (key: string) => {
    if (key === 'teacher') return // teacher always required
    setForm(f => ({
      ...f,
      agents: f.agents.includes(key)
        ? f.agents.filter(a => a !== key)
        : [...f.agents, key]
    }))
  }

  const submit = () => {
    setSubmitting(true)
    router.post('/teach/lessons', {
      ...form,
      agents: form.agents.map(a => ({ archetype: a })),
    }, { onFinish: () => setSubmitting(false) })
  }

  return (
    <TeacherLayout title="New Lesson">
      <div className="max-w-2xl mx-auto py-8 space-y-6">
        <h1 className="text-2xl font-semibold text-gray-900">Create a new lesson</h1>

        {/* Source input */}
        <div className="space-y-2">
          <label className="block text-sm font-medium text-gray-700">Lesson topic</label>
          <textarea
            className="w-full border rounded-lg p-3 text-sm resize-none h-32 focus:ring-2 focus:ring-blue-500"
            placeholder="Describe what you want to teach. E.g. 'The water cycle for 4th grade students, focusing on evaporation and condensation.'"
            value={form.source_text}
            onChange={e => setForm(f => ({ ...f, source_text: e.target.value }))}
          />
        </div>

        {/* Metadata row */}
        <div className="grid grid-cols-3 gap-3">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Title</label>
            <input className="w-full border rounded p-2 text-sm" value={form.title}
              onChange={e => setForm(f => ({ ...f, title: e.target.value }))}
              placeholder="The Water Cycle" />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Subject</label>
            <input className="w-full border rounded p-2 text-sm" value={form.subject}
              onChange={e => setForm(f => ({ ...f, subject: e.target.value }))}
              placeholder="Science" />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Grade</label>
            <select className="w-full border rounded p-2 text-sm" value={form.grade_level}
              onChange={e => setForm(f => ({ ...f, grade_level: e.target.value }))}>
              <option value="">General</option>
              {['K','1','2','3','4','5','6','7','8','9','10','11','12'].map(g =>
                <option key={g} value={g}>Grade {g}</option>)}
            </select>
          </div>
        </div>

        {/* Agent selection */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">Classroom agents</label>
          <p className="text-xs text-gray-500 mb-3">Choose which AI agents participate. The teacher is always included.</p>
          <div className="grid grid-cols-3 gap-2">
            {ARCHETYPES.map(a => {
              const selected = form.agents.includes(a.key)
              return (
                <button key={a.key} type="button"
                  onClick={() => toggleAgent(a.key)}
                  className={`flex items-center gap-2 p-3 rounded-lg border text-left text-sm transition-colors
                    ${selected ? 'border-blue-500 bg-blue-50 text-blue-800' : 'border-gray-200 hover:border-gray-300'}
                    ${a.required ? 'opacity-70 cursor-default' : 'cursor-pointer'}`}>
                  <span className="text-lg">{a.emoji}</span>
                  <div>
                    <div className="font-medium">{a.label}</div>
                    <div className="text-xs text-gray-500 capitalize">{a.role}</div>
                  </div>
                </button>
              )
            })}
          </div>
        </div>

        <button onClick={submit} disabled={submitting || !form.source_text || !form.title}
          className="w-full bg-blue-600 text-white py-3 rounded-lg font-medium
            hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
          {submitting ? 'Starting generation...' : 'Generate lesson'}
        </button>
      </div>
    </TeacherLayout>
  )
}
```

### `resources/js/pages/Teacher/Lessons/Show.tsx`

Lesson detail page with generation progress polling and scene cards. When generation is complete, shows each scene with a preview and edit affordance. Includes a Publish button.

```tsx
import { useState, useEffect } from 'react'
import { router, usePage } from '@inertiajs/react'
import TeacherLayout from '@/layouts/TeacherLayout'

export default function ShowLesson({ lesson }: { lesson: any }) {
  const [progress, setProgress] = useState(lesson.generation_progress)
  const [status, setStatus]     = useState(lesson.generation_status)
  const [scenes, setScenes]     = useState(lesson.scenes ?? [])

  // Poll while generating
  useEffect(() => {
    if (['completed','failed'].includes(status)) return
    const interval = setInterval(async () => {
      const res  = await fetch(`/teach/lessons/${lesson.id}/status`)
      const data = await res.json()
      setProgress(data.generation_progress)
      setStatus(data.generation_status)
      setScenes(data.scenes ?? [])
      if (['completed','failed'].includes(data.generation_status)) clearInterval(interval)
    }, 4000)
    return () => clearInterval(interval)
  }, [status])

  const progressPct = progress?.progress ?? 0

  return (
    <TeacherLayout title={lesson.title}>
      <div className="max-w-3xl mx-auto py-8 space-y-6">
        <div className="flex items-center justify-between">
          <h1 className="text-2xl font-semibold">{lesson.title}</h1>
          {status === 'completed' && (
            <button onClick={() => router.post(`/teach/lessons/${lesson.id}/publish`, {})}
              className="bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-green-700">
              Publish to Space
            </button>
          )}
        </div>

        {/* Progress bar */}
        {!['completed','failed'].includes(status) && (
          <div className="space-y-2">
            <div className="flex justify-between text-sm text-gray-600">
              <span>{progress?.message ?? 'Generating...'}</span>
              <span>{progressPct}%</span>
            </div>
            <div className="h-2 bg-gray-200 rounded-full overflow-hidden">
              <div className="h-full bg-blue-500 rounded-full transition-all duration-500"
                style={{ width: `${progressPct}%` }} />
            </div>
            {progress?.total_scenes > 0 && (
              <p className="text-xs text-gray-500">
                Scenes: {progress.scenes_generated} / {progress.total_scenes} ready
              </p>
            )}
          </div>
        )}

        {/* Scene cards */}
        <div className="space-y-3">
          {scenes.map((scene: any, i: number) => (
            <div key={scene.id} className="border rounded-lg p-4 flex items-center gap-3">
              <span className="text-2xl">
                {scene.scene_type === 'slide' ? '📊' : scene.scene_type === 'quiz' ? '📝' : scene.scene_type === 'interactive' ? '🔬' : '💬'}
              </span>
              <div className="flex-1 min-w-0">
                <p className="font-medium text-sm truncate">{i + 1}. {scene.title}</p>
                <p className="text-xs text-gray-500 capitalize">{scene.scene_type}</p>
              </div>
              <span className={`text-xs px-2 py-1 rounded-full font-medium ${
                scene.generation_status === 'ready' ? 'bg-green-100 text-green-700' :
                scene.generation_status === 'error' ? 'bg-red-100 text-red-700' :
                'bg-yellow-100 text-yellow-700'
              }`}>{scene.generation_status}</span>
            </div>
          ))}
        </div>

        {status === 'failed' && (
          <div className="bg-red-50 border border-red-200 rounded-lg p-4 text-sm text-red-700">
            Generation failed. Please try again or contact support.
          </div>
        )}
      </div>
    </TeacherLayout>
  )
}
```

---

## Step 14 — Frontend: Student classroom

### `resources/js/pages/Student/Classroom.tsx`

Three-panel classroom view with agent panel, content area, and whiteboard.

```tsx
import { useState, useEffect, useRef } from 'react'
import StudentLayout from '@/layouts/StudentLayout'
import AgentPanel from '@/components/classroom/AgentPanel'
import ClassroomContent from '@/components/classroom/ClassroomContent'
import WhiteboardCanvas from '@/components/classroom/WhiteboardCanvas'

export default function Classroom({ session, lesson, agents }: any) {
  const [messages, setMessages]         = useState<any[]>([])
  const [speakingAgentId, setSpeaking]  = useState<string | null>(null)
  const [whiteboard, setWhiteboard]     = useState<{ elements: any[], open: boolean }>({ elements: [], open: false })
  const [spotlightId, setSpotlightId]   = useState<string | null>(null)
  const [laserTarget, setLaserTarget]   = useState<string | null>(null)
  const [inputText, setInputText]       = useState('')
  const [sending, setSending]           = useState(false)
  const [cueUser, setCueUser]           = useState(false)
  const currentScene = session.lesson?.scenes?.[0] ?? null

  // Poll whiteboard
  useEffect(() => {
    const interval = setInterval(async () => {
      if (sending) return
      const res  = await fetch(`/learn/classroom/${session.id}/whiteboard`)
      const data = await res.json()
      setWhiteboard(data)
    }, 800)
    return () => clearInterval(interval)
  }, [session.id, sending])

  const send = async () => {
    if (!inputText.trim() || sending) return
    setSending(true)
    setCueUser(false)

    const userMsg = { id: Date.now(), role: 'student', content: inputText }
    setMessages(prev => [...prev, userMsg])
    setInputText('')

    const res = await fetch(`/learn/classroom/${session.id}/message`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': (document.querySelector('meta[name=csrf-token]') as any)?.content },
      body: JSON.stringify({ content: inputText }),
    })

    const reader = res.body!.getReader()
    const decoder = new TextDecoder()
    let currentAgentMsg: any = null

    while (true) {
      const { done, value } = await reader.read()
      if (done) break
      const lines = decoder.decode(value).split('\n')
      for (const line of lines) {
        if (!line.startsWith('data: ')) continue
        const event = JSON.parse(line.slice(6))

        if (event.type === 'agent_start') {
          setSpeaking(event.data.agentId)
          currentAgentMsg = { id: Date.now(), role: 'agent', agentId: event.data.agentId, agentName: event.data.agentName, agentColor: event.data.agentColor, agentEmoji: event.data.agentEmoji, content: '' }
          setMessages(prev => [...prev, currentAgentMsg])
        }
        if (event.type === 'text_delta' && currentAgentMsg) {
          currentAgentMsg.content += event.data.content
          setMessages(prev => prev.map(m => m.id === currentAgentMsg.id ? { ...currentAgentMsg } : m))
        }
        if (event.type === 'action') {
          const { actionName, params } = event.data
          if (actionName === 'spotlight')   setSpotlightId(params.elementId)
          if (actionName === 'laser')       setLaserTarget(params.elementId)
        }
        if (event.type === 'agent_end') {
          setSpeaking(null)
          currentAgentMsg = null
        }
        if (event.type === 'cue_user') setCueUser(true)
      }
    }
    setSending(false)
  }

  const exitClassroom = () => {
    fetch(`/learn/classroom/${session.id}/end`, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name=csrf-token]') as any)?.content },
    }).then(() => window.location.href = '/')
  }

  return (
    <StudentLayout title={lesson.title}>
      <div className="flex h-screen bg-gray-50">
        {/* Left: Agent panel */}
        <div className="w-48 flex-shrink-0 border-r bg-white p-3 flex flex-col gap-3">
          <AgentPanel agents={agents} speakingAgentId={speakingAgentId} />
          <button onClick={exitClassroom}
            className="mt-auto text-xs text-gray-400 hover:text-gray-600 text-center py-2">
            Exit classroom
          </button>
        </div>

        {/* Center: Content + chat */}
        <div className="flex-1 flex flex-col min-w-0">
          <ClassroomContent
            scene={currentScene}
            messages={messages}
            spotlightId={spotlightId}
            laserTarget={laserTarget}
            session={session}
            onQuizSubmit={async (sceneId: string, questionIndex: number, answer: any) => {
              const res = await fetch(`/learn/classroom/${session.id}/quiz/${sceneId}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': (document.querySelector('meta[name=csrf-token]') as any)?.content },
                body: JSON.stringify({ question_index: questionIndex, answer }),
              })
              return res.json()
            }}
          />
          {/* Input */}
          <div className="border-t bg-white p-3 flex gap-2">
            <input
              className="flex-1 border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500"
              placeholder={cueUser ? "Your turn — what do you think?" : "Ask a question or respond..."}
              value={inputText}
              onChange={e => setInputText(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && !e.shiftKey && send()}
              disabled={sending}
            />
            <button onClick={send} disabled={sending || !inputText.trim()}
              className="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50">
              Send
            </button>
          </div>
        </div>

        {/* Right: Whiteboard */}
        {whiteboard.open && (
          <div className="w-96 flex-shrink-0 border-l bg-white">
            <WhiteboardCanvas elements={whiteboard.elements} />
          </div>
        )}
      </div>
    </StudentLayout>
  )
}
```

### `resources/js/components/classroom/AgentPanel.tsx`
```tsx
export default function AgentPanel({ agents, speakingAgentId }: any) {
  return (
    <div className="flex flex-col gap-2">
      <p className="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Classroom</p>
      {agents.map((agent: any) => (
        <div key={agent.id} className="flex items-center gap-2">
          <div className="relative">
            <div className="w-9 h-9 rounded-full flex items-center justify-center text-lg"
              style={{ backgroundColor: agent.color_hex + '20', border: `2px solid ${agent.color_hex}` }}>
              {agent.avatar_emoji}
            </div>
            {speakingAgentId === agent.id && (
              <span className="absolute -top-0.5 -right-0.5 w-3 h-3 bg-green-400 rounded-full animate-pulse border-2 border-white" />
            )}
          </div>
          <div className="min-w-0">
            <p className="text-xs font-medium text-gray-800 truncate">{agent.display_name}</p>
            <p className="text-xs text-gray-400 capitalize">{agent.role}</p>
          </div>
        </div>
      ))}
    </div>
  )
}
```

### `resources/js/components/classroom/WhiteboardCanvas.tsx`
```tsx
import { useEffect, useRef } from 'react'

// Renders whiteboard elements in a 1000×562 coordinate space
export default function WhiteboardCanvas({ elements }: { elements: any[] }) {
  const containerRef = useRef<HTMLDivElement>(null)

  return (
    <div className="h-full flex flex-col">
      <div className="p-2 border-b text-xs font-medium text-gray-500">Whiteboard</div>
      <div ref={containerRef} className="flex-1 relative overflow-hidden bg-white">
        <div className="absolute inset-0" style={{ aspectRatio: '1000/562' }}>
          <svg viewBox="0 0 1000 562" className="absolute inset-0 w-full h-full pointer-events-none opacity-10">
            <rect x="0" y="0" width="1000" height="562" fill="none" stroke="#e5e7eb" strokeWidth="1"/>
          </svg>
          {elements.map((el, i) => (
            <WhiteboardElement key={el.id} element={el} index={i} />
          ))}
        </div>
      </div>
    </div>
  )
}

function WhiteboardElement({ element, index }: { element: any, index: number }) {
  // All positions are in 1000×562 space. Parent div uses aspect-ratio to scale.
  const baseStyle: React.CSSProperties = {
    position: 'absolute',
    left: `${(element.left / 1000) * 100}%`,
    top: `${(element.top / 562) * 100}%`,
    width: `${((element.width ?? 100) / 1000) * 100}%`,
    animation: `wbAppear 0.45s ease forwards`,
    animationDelay: `${index * 0.05}s`,
    opacity: 0,
  }

  if (element.type === 'text') {
    return (
      <div style={baseStyle}>
        <div dangerouslySetInnerHTML={{ __html: element.content ?? '' }}
          style={{ color: element.color ?? '#333', overflow: 'hidden' }} />
      </div>
    )
  }

  if (element.type === 'shape') {
    const borderRadius = element.shape === 'circle' ? '50%' : element.shape === 'triangle' ? '0' : '4px'
    return (
      <div style={{ ...baseStyle, height: `${((element.height ?? 100) / 562) * 100}%`,
        backgroundColor: element.fill_color ?? '#5b9bd5', borderRadius }} />
    )
  }

  if (element.type === 'latex') {
    return (
      <div style={baseStyle} className="flex items-center">
        <span className="text-sm font-mono">{element.latex}</span>
      </div>
    )
  }

  return null // chart and table render as placeholders for now
}
```

Add to your global CSS (in `resources/css/app.css`):
```css
@keyframes wbAppear {
  from { opacity: 0; transform: scale(0.92) translateY(8px); filter: blur(4px); }
  to   { opacity: 1; transform: scale(1) translateY(0); filter: blur(0); }
}
```

---

## Step 15 — Update Compass View for agent messages

In your existing Compass View (`/teach/compass`), add agent messages to the
session timeline. Agent messages have `sender_type = 'agent'` — render them
with the agent's color and emoji alongside student messages so teachers can
see exactly what every agent said.

In `resources/js/components/compass/StudentCard.tsx` (or equivalent), update
the message list to include agent messages:

```tsx
{messages.map(msg => (
  <div key={msg.id} className={`text-xs py-1 px-2 rounded ${
    msg.sender_type === 'student'
      ? 'bg-amber-50 text-amber-800 self-end'
      : msg.sender_type === 'agent'
      ? 'bg-blue-50 text-blue-800'
      : 'text-gray-400 italic'
  }`}>
    {msg.sender_type === 'agent' && (
      <span className="font-medium mr-1">{msg.agent?.avatar_emoji} {msg.agent?.display_name}:</span>
    )}
    {msg.content_text}
  </div>
))}
```

---

## Step 16 — Register service providers

In `app/Providers/AppServiceProvider.php`, bind services:

```php
$this->app->singleton(LessonGeneratorService::class);
$this->app->singleton(DirectorService::class);
$this->app->singleton(AgentOrchestrator::class);
$this->app->singleton(WhiteboardService::class);
$this->app->singleton(QuizGraderService::class);
$this->app->singleton(InteractiveHtmlProcessor::class);
```

---

## Step 17 — Configure Horizon queues

In `config/horizon.php`, ensure the `default` queue is included:

```php
'environments' => [
    'production' => [
        'supervisor-1' => [
            'queue'   => ['critical', 'high', 'default', 'low'],
            'balance' => 'auto',
            'processes'=> 10,
            'tries'   => 3,
        ],
    ],
],
```

---

## Step 18 — Smoke tests

```bash
php artisan test --filter=Classroom
```

Manual walkthrough:
1. Teacher creates a lesson with topic "photosynthesis for grade 5"
2. Verify generation status reaches 'completed' (poll `/teach/lessons/{id}/status`)
3. Verify at least 4 scenes created with status 'ready'
4. Teacher publishes lesson to a Space
5. Student navigates to the Space → Start Classroom
6. Send a message → verify SSE stream returns agent_start, text_delta, agent_end events
7. Verify whiteboard polling returns updated elements after a wb_draw action
8. Submit a quiz answer → verify score and feedback returned
9. Check Compass View → verify agent messages visible alongside student messages

---

## Acceptance checklist

- [ ] All 7 migrations run without error
- [ ] Lesson creation with topic text triggers generation job
- [ ] Generation status polling returns accurate progress
- [ ] At least one slide scene, one quiz scene generated and status=ready
- [ ] Interactive scene generates valid HTML (or falls back to slide on failure)
- [ ] Student can start a classroom session
- [ ] Student message returns multi-agent SSE stream
- [ ] Agent messages stored in classroom_messages
- [ ] Whiteboard state updates after wb_draw actions
- [ ] Quiz grade endpoint returns is_correct, score, feedback
- [ ] Safety filter intercepts flagged student messages
- [ ] Agent output runs through safety filter
- [ ] Interactive HTML validation rejects fetch() patterns
- [ ] Compass View shows agent messages with agent name/emoji
- [ ] "Exit classroom" ends the session cleanly
- [ ] No OpenMAIC code in any file — all implementation original

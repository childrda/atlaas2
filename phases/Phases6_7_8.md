# ATLAAS — Phase 6: Teacher Toolkit
## Prerequisite: Phase 5 checklist fully passing
## Stop when this works: Teacher can run all 7 built-in tools and see streaming output

---

## What you're building in this phase
- TeacherTool and ToolRun models and migrations
- 7 seeded built-in tools (lesson planner, rubric builder, etc.)
- Dynamic form generation from JSON schema
- SSE streaming tool output (same pattern as student chat)
- Tool run history

---

## Step 1 — Migrations

### Teacher tools table
```php
Schema::create('teacher_tools', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('district_id')->nullable()->constrained()->nullOnDelete(); // null = built-in
    $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
    $table->string('name');
    $table->string('slug')->unique();
    $table->text('description');
    $table->string('icon')->default('sparkles');
    $table->string('category'); // lesson_plan|rubric|assessment|parent_comm|differentiation|feedback|custom
    $table->text('system_prompt_template'); // {{variable}} placeholders
    $table->jsonb('input_schema')->default('[]'); // field definitions — drives form generation
    $table->boolean('is_built_in')->default(false);
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

### Tool runs table
```php
Schema::create('tool_runs', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('teacher_id')->constrained('users')->cascadeOnDelete();
    $table->foreignUuid('tool_id')->constrained('teacher_tools')->cascadeOnDelete();
    $table->jsonb('inputs');
    $table->text('output')->nullable();
    $table->integer('tokens_used')->default(0);
    $table->timestamps();
    $table->index(['teacher_id', 'created_at']);
});
```

```bash
php artisan migrate
```

---

## Step 2 — Models

### `app/Models/TeacherTool.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherTool extends BaseModel
{
    protected $fillable = [
        'district_id', 'created_by', 'name', 'slug', 'description',
        'icon', 'category', 'system_prompt_template', 'input_schema',
        'is_built_in', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'input_schema' => 'array',
            'is_built_in'  => 'boolean',
            'is_active'    => 'boolean',
        ];
    }

    public function runs(): HasMany { return $this->hasMany(ToolRun::class, 'tool_id'); }
}
```

### `app/Models/ToolRun.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToolRun extends BaseModel
{
    protected $fillable = [
        'teacher_id', 'tool_id', 'inputs', 'output', 'tokens_used',
    ];

    protected function casts(): array
    {
        return ['inputs' => 'array'];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('district', function ($query) {
            if (auth()->check()) {
                $query->whereHas('teacher', fn($q) =>
                    $q->where('district_id', auth()->user()->district_id)
                );
            }
        });
    }

    public function teacher(): BelongsTo { return $this->belongsTo(User::class, 'teacher_id'); }
    public function tool(): BelongsTo    { return $this->belongsTo(TeacherTool::class, 'tool_id'); }
}
```

---

## Step 3 — Built-in tools seeder

Create `database/seeders/BuiltInToolsSeeder.php`:

Each tool's `input_schema` is a JSON array of field definitions.
Each field has: `name`, `label`, `type`, `required`, `placeholder` (optional), `options` (for select/checkbox_group).
The frontend reads this to auto-generate the form.

```php
<?php

namespace Database\Seeders;

use App\Models\TeacherTool;
use Illuminate\Database\Seeder;

class BuiltInToolsSeeder extends Seeder
{
    public function run(): void
    {
        $tools = [
            [
                'name'        => 'Lesson Planner',
                'slug'        => 'lesson-planner',
                'icon'        => 'book-open',
                'category'    => 'lesson_plan',
                'description' => 'Generate a complete lesson plan in minutes.',
                'system_prompt_template' =>
                    'You are an expert K-12 curriculum designer. ' .
                    'Create a detailed lesson plan for: Subject: {{subject}}, Grade: {{grade_level}}, ' .
                    'Objective: {{objective}}, Duration: {{duration}} minutes. ' .
                    '{{accommodations ? "Accommodations: " + accommodations : ""}} ' .
                    'Format with sections: Learning Objective, Materials, Hook (5 min), ' .
                    'Direct Instruction, Guided Practice, Independent Practice, Closure, Assessment.',
                'input_schema' => [
                    ['name' => 'subject',        'label' => 'Subject',           'type' => 'text',     'required' => true,  'placeholder' => 'e.g. Grade 4 Science'],
                    ['name' => 'grade_level',    'label' => 'Grade Level',       'type' => 'text',     'required' => true,  'placeholder' => 'e.g. Grade 4'],
                    ['name' => 'objective',      'label' => 'Learning Objective','type' => 'textarea', 'required' => true,  'placeholder' => 'Students will be able to...'],
                    ['name' => 'duration',       'label' => 'Duration (minutes)','type' => 'number',   'required' => true,  'placeholder' => '60'],
                    ['name' => 'accommodations', 'label' => 'Accommodations',    'type' => 'textarea', 'required' => false, 'placeholder' => 'Any IEP, ELL, or other needs...'],
                ],
            ],
            [
                'name'        => 'Rubric Builder',
                'slug'        => 'rubric-builder',
                'icon'        => 'table',
                'category'    => 'rubric',
                'description' => 'Create a detailed grading rubric for any assignment.',
                'system_prompt_template' =>
                    'Create a detailed rubric for: Assignment: {{assignment_type}}, ' .
                    'Grade: {{grade_level}}, Subject: {{subject}}. ' .
                    'Include {{criteria_count}} criteria and {{performance_levels}} performance levels. ' .
                    'Format as a markdown table. Be specific about observable behaviors at each level.',
                'input_schema' => [
                    ['name' => 'assignment_type',    'label' => 'Assignment Type',        'type' => 'text',   'required' => true, 'placeholder' => 'e.g. Persuasive Essay'],
                    ['name' => 'subject',            'label' => 'Subject',                'type' => 'text',   'required' => true],
                    ['name' => 'grade_level',        'label' => 'Grade Level',            'type' => 'text',   'required' => true],
                    ['name' => 'criteria_count',     'label' => 'Number of Criteria',     'type' => 'number', 'required' => true, 'placeholder' => '4'],
                    ['name' => 'performance_levels', 'label' => 'Performance Levels',     'type' => 'number', 'required' => true, 'placeholder' => '4'],
                ],
            ],
            [
                'name'        => 'Assessment Generator',
                'slug'        => 'assessment-generator',
                'icon'        => 'clipboard-check',
                'category'    => 'assessment',
                'description' => 'Generate quizzes and assessments with answer keys.',
                'system_prompt_template' =>
                    'Create a {{difficulty}} level assessment on: {{topic}} for Grade {{grade_level}}. ' .
                    'Include {{question_count}} questions of these types: {{question_types}}. ' .
                    'Include a complete answer key at the end.',
                'input_schema' => [
                    ['name' => 'topic',           'label' => 'Topic',             'type' => 'text',          'required' => true],
                    ['name' => 'grade_level',     'label' => 'Grade Level',       'type' => 'text',          'required' => true],
                    ['name' => 'question_count',  'label' => 'Number of Questions','type' => 'number',        'required' => true,  'placeholder' => '10'],
                    ['name' => 'difficulty',      'label' => 'Difficulty',        'type' => 'select',        'required' => true,  'options' => ['Easy', 'Medium', 'Hard', 'Mixed']],
                    ['name' => 'question_types',  'label' => 'Question Types',    'type' => 'checkbox_group','required' => true,  'options' => ['Multiple choice', 'Short answer', 'True/false', 'Essay']],
                ],
            ],
            [
                'name'        => 'Differentiation Helper',
                'slug'        => 'differentiation-helper',
                'icon'        => 'users',
                'category'    => 'differentiation',
                'description' => 'Adapt any lesson or activity for diverse learners.',
                'system_prompt_template' =>
                    'Adapt this lesson or activity for students with these needs: {{student_needs}}. ' .
                    'Provide one adapted version per need. Keep the same learning objective. ' .
                    'Original activity:\n{{activity_text}}',
                'input_schema' => [
                    ['name' => 'activity_text', 'label' => 'Original Activity',  'type' => 'textarea',      'required' => true,  'placeholder' => 'Paste your lesson or activity here...'],
                    ['name' => 'student_needs', 'label' => 'Student Needs',      'type' => 'checkbox_group','required' => true,  'options' => ['English Language Learners (ELL)', 'IEP supports', 'Gifted/Advanced', '504 accommodations']],
                ],
            ],
            [
                'name'        => 'Parent Communication Drafter',
                'slug'        => 'parent-comms',
                'icon'        => 'mail',
                'category'    => 'parent_comm',
                'description' => 'Draft professional parent and guardian emails quickly.',
                'system_prompt_template' =>
                    'Draft a {{tone}} email to a parent/guardian. ' .
                    'Situation: {{situation_type}}. Context: {{context}}. ' .
                    'Be professional, clear, and actionable. Include a subject line.',
                'input_schema' => [
                    ['name' => 'situation_type', 'label' => 'Situation',  'type' => 'select',  'required' => true, 'options' => ['Academic concern', 'Positive recognition', 'Progress update', 'Behavior concern', 'Meeting request']],
                    ['name' => 'context',        'label' => 'Context',    'type' => 'textarea','required' => true, 'placeholder' => 'Brief description (do not include student full name)...'],
                    ['name' => 'tone',           'label' => 'Tone',       'type' => 'select',  'required' => true, 'options' => ['Warm and supportive', 'Professional', 'Urgent']],
                ],
            ],
            [
                'name'        => 'Feedback Generator',
                'slug'        => 'feedback-generator',
                'icon'        => 'message-square',
                'category'    => 'feedback',
                'description' => 'Generate specific, growth-oriented feedback on student work.',
                'system_prompt_template' =>
                    'Write growth-oriented feedback for a Grade {{grade_level}} student. ' .
                    'Assignment goals: {{assignment_goals}}. ' .
                    'Student work:\n{{student_work}}\n\n' .
                    'Celebrate strengths and identify 1-2 clear, specific areas for improvement. Be encouraging.',
                'input_schema' => [
                    ['name' => 'grade_level',     'label' => 'Grade Level',      'type' => 'text',    'required' => true],
                    ['name' => 'assignment_goals','label' => 'Assignment Goals', 'type' => 'textarea','required' => true,  'placeholder' => 'What was the student trying to accomplish?'],
                    ['name' => 'student_work',    'label' => 'Student Work',     'type' => 'textarea','required' => true,  'placeholder' => 'Paste student work here...'],
                ],
            ],
            [
                'name'        => 'IEP Accommodation Suggester',
                'slug'        => 'iep-accommodations',
                'icon'        => 'heart-handshake',
                'category'    => 'differentiation',
                'description' => 'Get specific accommodation suggestions by disability category.',
                'system_prompt_template' =>
                    'Suggest 5-8 practical classroom accommodations for a student with {{disability_category}} ' .
                    'in a {{subject}} class, Grade {{grade_level}}. Activity type: {{activity_type}}. ' .
                    'Each accommodation should be specific and immediately implementable by a classroom teacher.',
                'input_schema' => [
                    ['name' => 'disability_category','label' => 'Category',      'type' => 'select', 'required' => true, 'options' => ['ADHD', 'Dyslexia', 'Autism Spectrum', 'Hearing Impairment', 'Visual Impairment', 'Anxiety', 'Processing Disorder', 'Other']],
                    ['name' => 'subject',            'label' => 'Subject',       'type' => 'text',   'required' => true],
                    ['name' => 'grade_level',        'label' => 'Grade Level',   'type' => 'text',   'required' => true],
                    ['name' => 'activity_type',      'label' => 'Activity Type', 'type' => 'text',   'required' => true, 'placeholder' => 'e.g. written test, group discussion, lab activity'],
                ],
            ],
        ];

        foreach ($tools as $tool) {
            TeacherTool::firstOrCreate(
                ['slug' => $tool['slug']],
                array_merge($tool, ['is_built_in' => true, 'input_schema' => json_encode($tool['input_schema'])])
            );
        }
    }
}
```

Add to `DatabaseSeeder`:
```php
$this->call(BuiltInToolsSeeder::class);
```

```bash
php artisan db:seed --class=BuiltInToolsSeeder
```

---

## Step 4 — Toolkit controller

`app/Http/Controllers/Teacher/ToolkitController.php`:
```php
<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\TeacherTool;
use App\Models\ToolRun;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use OpenAI\Laravel\Facades\OpenAI;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ToolkitController extends Controller
{
    public function index(): Response
    {
        $tools = TeacherTool::where('is_active', true)
            ->where(fn($q) => $q->whereNull('district_id')
                ->orWhere('district_id', auth()->user()->district_id))
            ->orderByDesc('is_built_in')
            ->orderBy('name')
            ->get();

        $recentRuns = ToolRun::where('teacher_id', auth()->id())
            ->with('tool:id,name,icon,slug')
            ->latest()
            ->limit(5)
            ->get();

        return Inertia::render('Teacher/Toolkit/Index', [
            'tools'      => $tools,
            'recentRuns' => $recentRuns,
        ]);
    }

    public function show(TeacherTool $tool): Response
    {
        return Inertia::render('Teacher/Toolkit/Show', ['tool' => $tool]);
    }

    public function run(Request $request, TeacherTool $tool): StreamedResponse
    {
        abort_unless($tool->is_active, 403);

        $request->validate(['inputs' => 'required|array']);

        // Interpolate inputs into the prompt template
        $prompt = $tool->system_prompt_template;
        foreach ($request->input('inputs') as $key => $value) {
            $value  = is_array($value) ? implode(', ', $value) : (string) $value;
            $prompt = str_replace("{{{$key}}}", $value, $prompt);
        }

        $teacher = auth()->user();
        $runId   = (string) Str::uuid();

        return response()->stream(
            function () use ($prompt, $tool, $request, $teacher, $runId) {
                $fullOutput = '';

                $stream = OpenAI::chat()->createStreamed([
                    'model'      => config('openai.model'),
                    'messages'   => [['role' => 'user', 'content' => $prompt]],
                    'max_tokens' => 1500,
                ]);

                foreach ($stream as $response) {
                    $chunk = $response->choices[0]->delta->content ?? '';
                    if ($chunk !== '') {
                        $fullOutput .= $chunk;
                        echo "data: " . json_encode(['type' => 'chunk', 'content' => $chunk]) . "\n\n";
                        ob_flush();
                        flush();
                    }
                }

                // Store the run after streaming completes
                ToolRun::create([
                    'id'         => $runId,
                    'teacher_id' => $teacher->id,
                    'tool_id'    => $tool->id,
                    'inputs'     => $request->input('inputs'),
                    'output'     => $fullOutput,
                ]);

                echo "data: " . json_encode(['type' => 'done', 'run_id' => $runId]) . "\n\n";
                ob_flush();
                flush();
            },
            200,
            [
                'Content-Type'      => 'text/event-stream',
                'Cache-Control'     => 'no-cache',
                'X-Accel-Buffering' => 'no',
                'Connection'        => 'keep-alive',
            ]
        );
    }
}
```

Add routes in teacher group:
```php
Route::get('toolkit', [ToolkitController::class, 'index'])->name('toolkit.index');
Route::get('toolkit/{tool:slug}', [ToolkitController::class, 'show'])->name('toolkit.show');
Route::post('toolkit/{tool:slug}/run', [ToolkitController::class, 'run'])->name('toolkit.run');
```

---

## Step 5 — React pages

### `resources/js/Pages/Teacher/Toolkit/Index.tsx`
Two-column layout:
- Left: grid of tool cards (icon, name, description, category badge)
- Right: "Recent runs" list (tool name, time, link to re-run)

### `resources/js/Pages/Teacher/Toolkit/Show.tsx`
Split panel:
- Left: `DynamicToolForm` — generates fields from `tool.input_schema`
- Right: `StreamingOutput` — shows output as it streams in

### `resources/js/Components/Toolkit/DynamicToolForm.tsx`
```tsx
// Reads tool.input_schema array and renders fields:
// type: 'text'          → <input type="text" />
// type: 'textarea'      → <textarea />
// type: 'number'        → <input type="number" />
// type: 'select'        → <select> with field.options
// type: 'checkbox_group'→ group of checkboxes, value is string[]
//
// On submit: POST to toolkit/{slug}/run with { inputs: { fieldName: value, ... } }
// Then open SSE stream using the same fetch + ReadableStream pattern as student chat
```

### `resources/js/Components/Toolkit/StreamingOutput.tsx`
```tsx
// Props: content: string, isStreaming: boolean
// Shows content as markdown (use a lightweight renderer or pre-wrap with whitespace-pre-wrap)
// Blinking cursor at end while streaming
// When done: Copy button + Regenerate button
// Regenerate simply calls the parent's submit handler again
```

---

## Step 6 — Verify

**Checklist — do not move to Phase 7 until all pass:**

- [ ] All 7 tools appear on `/teach/toolkit`
- [ ] Each tool card shows the correct icon, name, and category
- [ ] Selecting a tool shows the correct form fields for that tool
- [ ] Submitting the Lesson Planner form → output streams token by token
- [ ] Copy button copies full output to clipboard
- [ ] Selecting checkbox_group fields (Assessment Generator → question types) works correctly
- [ ] Tool run is stored in the `tool_runs` table after streaming completes
- [ ] Recent runs list on index page shows last 5 runs
- [ ] Switching model in `.env` → toolkit still works without code changes

---

## Phase 6 complete. Next: Phase 7 — Discover library.

---
---

# ATLAAS — Phase 7: Discover Library
## Prerequisite: Phase 6 checklist fully passing
## Stop when this works: Teachers can publish spaces to the library, search, and import them

---

## What you're building in this phase
- Meilisearch via Laravel Scout for full-text search
- SpaceLibraryItem model
- Discover search, filter, and sort page
- Import flow (deep copy a public space into your account)
- Basic rating system

---

## Step 1 — Install Scout and Meilisearch

```bash
composer require laravel/scout meilisearch/meilisearch-php http-interop/http-factory-guzzle
php artisan vendor:publish --provider="Laravel\Scout\ScoutServiceProvider"
```

In `.env`:
```
SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://localhost:7700
MEILISEARCH_KEY=your-master-key
```

Start Meilisearch locally (Docker recommended):
```bash
docker run -d -p 7700:7700 \
  -e MEILI_MASTER_KEY=your-master-key \
  getmeili/meilisearch:v1.7
```

---

## Step 2 — Migration

```php
Schema::create('space_library_items', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('space_id')->constrained('learning_spaces')->cascadeOnDelete();
    $table->string('title');
    $table->text('description')->nullable();
    $table->string('subject')->nullable();
    $table->string('grade_band')->nullable(); // K-2|3-5|6-8|9-12
    $table->jsonb('tags')->default('[]');
    $table->integer('download_count')->default(0);
    $table->decimal('rating', 3, 2)->default(0.00);
    $table->integer('rating_count')->default(0);
    $table->boolean('district_approved')->default(false);
    $table->timestamp('published_at')->nullable();
    $table->timestamps();
    $table->index(['subject', 'grade_band']);
    $table->index(['download_count']);
});
```

```bash
php artisan migrate
```

---

## Step 3 — Make LearningSpace searchable

Add the `Searchable` trait to `LearningSpace`:
```php
use Laravel\Scout\Searchable;

// In the class:
use Searchable;

public function toSearchableArray(): array
{
    return [
        'id'          => $this->id,
        'title'       => $this->title,
        'description' => $this->description,
        'subject'     => $this->subject,
        'grade_level' => $this->grade_level,
        'goals'       => implode(' ', $this->goals ?? []),
    ];
}

// Only index published, public, non-archived spaces
public function shouldBeSearchable(): bool
{
    return $this->is_published && $this->is_public && !$this->is_archived;
}
```

Import existing spaces:
```bash
php artisan scout:import "App\Models\LearningSpace"
```

---

## Step 4 — SpaceLibraryItem model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpaceLibraryItem extends BaseModel
{
    protected $fillable = [
        'space_id', 'title', 'description', 'subject',
        'grade_band', 'tags', 'district_approved', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'tags'              => 'array',
            'district_approved' => 'boolean',
            'published_at'      => 'datetime',
        ];
    }

    public function space(): BelongsTo { return $this->belongsTo(LearningSpace::class); }
}
```

---

## Step 5 — Update SpaceController publish action

Replace the existing `publish()` method with:
```php
public function publish(Request $request, LearningSpace $space): RedirectResponse
{
    $this->authorize('update', $space);

    $data = $request->validate([
        'share_to_discover' => 'boolean',
        'grade_band'        => 'nullable|in:K-2,3-5,6-8,9-12',
        'tags'              => 'nullable|array|max:5',
        'tags.*'            => 'string|max:30',
    ]);

    $space->update(['is_published' => true]);

    if ($request->boolean('share_to_discover')) {
        $space->update(['is_public' => true]);

        SpaceLibraryItem::updateOrCreate(
            ['space_id' => $space->id],
            [
                'title'        => $space->title,
                'description'  => $space->description,
                'subject'      => $space->subject,
                'grade_band'   => $data['grade_band'] ?? null,
                'tags'         => $data['tags'] ?? [],
                'published_at' => now(),
            ]
        );

        // Triggers Scout to index this space
        $space->searchable();
    }

    return back()->with('success', 'Space published.');
}
```

Update the Space publish UI to include "Share to Discover" checkbox, grade band dropdown, and tags input.

---

## Step 6 — Discover controller

`app/Http/Controllers/Teacher/DiscoverController.php`:
```php
<?php

namespace App\Http\Controllers\Teacher;

use App\Helpers\JoinCode;
use App\Http\Controllers\Controller;
use App\Models\LearningSpace;
use App\Models\SpaceLibraryItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DiscoverController extends Controller
{
    public function index(Request $request): Response
    {
        $query     = $request->input('q', '');
        $subject   = $request->input('subject');
        $gradeBand = $request->input('grade_band');
        $sort      = $request->input('sort', 'popular'); // popular|newest|rating

        if ($query) {
            $spaceIds = LearningSpace::search($query)->keys();
            $builder  = SpaceLibraryItem::whereIn('space_id', $spaceIds);
        } else {
            $builder = SpaceLibraryItem::whereNotNull('published_at');
        }

        if ($subject)   $builder->where('subject', $subject);
        if ($gradeBand) $builder->where('grade_band', $gradeBand);

        $builder = match ($sort) {
            'newest' => $builder->orderByDesc('published_at'),
            'rating' => $builder->orderByDesc('rating'),
            default  => $builder->orderByDesc('download_count'),
        };

        // Respect district's discover scope setting (future: district can restrict to own spaces)
        $items = $builder
            ->with('space.teacher:id,school_id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Teacher/Discover/Index', [
            'items'   => $items,
            'filters' => compact('query', 'subject', 'gradeBand', 'sort'),
        ]);
    }

    public function import(SpaceLibraryItem $item): RedirectResponse
    {
        $original = $item->space;

        // Deep copy — new record, new join code, not public, assigned to importing teacher
        $copy = $original->replicate(['join_code', 'is_public', 'classroom_id', 'is_published']);
        $copy->title      = $original->title . ' (Imported)';
        $copy->teacher_id = auth()->id();
        $copy->district_id = auth()->user()->district_id;
        $copy->is_public   = false;
        $copy->is_published = false;
        $copy->join_code   = JoinCode::generate('learning_spaces');
        $copy->save();

        $item->increment('download_count');

        return redirect()->route('teacher.spaces.edit', $copy)
            ->with('success', 'Space imported. Customize it before sharing with students.');
    }

    public function rate(Request $request, SpaceLibraryItem $item): JsonResponse
    {
        $data = $request->validate(['rating' => 'required|integer|min:1|max:5']);

        $newCount  = $item->rating_count + 1;
        $newRating = (($item->rating * $item->rating_count) + $data['rating']) / $newCount;

        $item->update([
            'rating'       => round($newRating, 2),
            'rating_count' => $newCount,
        ]);

        return response()->json([
            'rating'       => $item->rating,
            'rating_count' => $item->rating_count,
        ]);
    }
}
```

Add routes in teacher group:
```php
Route::get('discover', [DiscoverController::class, 'index'])->name('discover.index');
Route::post('discover/{item}/import', [DiscoverController::class, 'import'])->name('discover.import');
Route::post('discover/{item}/rate', [DiscoverController::class, 'rate'])->name('discover.rate');
```

---

## Step 7 — React pages

### `resources/js/Pages/Teacher/Discover/Index.tsx`
- Search bar at top (debounced, updates URL params via Inertia `router.get`)
- Filter chips: Subject, Grade Band, Sort (popular / newest / rating)
- Grid of `SpaceLibraryItem` cards
- Each card: title, subject, grade band, school name (not teacher name — privacy), star rating, download count, tags, "District Approved" badge
- "Add to My Spaces" button → POST to import route

Star rating component:
```tsx
function StarRating({ rating, count }: { rating: number; count: number }) {
    return (
        <div className="flex items-center gap-1">
            {[1, 2, 3, 4, 5].map(star => (
                <span key={star} className={star <= Math.round(rating) ? 'text-amber-400' : 'text-gray-200'}>
                    ★
                </span>
            ))}
            <span className="text-xs text-gray-400">({count})</span>
        </div>
    );
}
```

---

## Step 8 — Verify

**Checklist — do not move to Phase 8 until all pass:**

- [ ] Teacher can publish a space with "Share to Discover" checked
- [ ] Space appears in Discover search results
- [ ] Searching by keyword returns relevant spaces (Meilisearch working)
- [ ] Filter by subject narrows results correctly
- [ ] Sort by "newest" and "rating" work
- [ ] "Add to My Spaces" creates a copy with a new join code in the teacher's account
- [ ] Imported space shows as unpublished and opens in the editor
- [ ] Download count increments on import
- [ ] Rating a space updates the displayed rating

---

## Phase 7 complete. Next: Phase 8 — Docker and deployment.

---
---

# ATLAAS — Phase 8: Docker & Deployment
## Prerequisite: Phase 7 checklist fully passing — full app working locally
## Stop when this works: The entire app runs with `docker compose up`

---

## What you're building in this phase
- Multi-stage Dockerfile
- Docker Compose with all services
- Nginx config with WebSocket support for Reverb
- Production environment configuration
- First-run setup script
- Backup script

---

## Step 1 — Dockerfile

Create at project root:
```dockerfile
# ─────────────────────────────────────────────
# Stage 1: Build frontend assets
# ─────────────────────────────────────────────
FROM node:20-alpine AS frontend
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

# ─────────────────────────────────────────────
# Stage 2: PHP runtime
# ─────────────────────────────────────────────
FROM php:8.3-fpm-alpine AS runtime

# System dependencies
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    libpng-dev \
    oniguruma-dev \
    icu-dev \
    && docker-php-ext-install \
        pdo_pgsql \
        zip \
        gd \
        bcmath \
        pcntl \
        sockets \
        intl \
        opcache

# Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP dependencies (production only)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy app
COPY . .

# Copy compiled frontend assets from Stage 1
COPY --from=frontend /app/public/build ./public/build

# Permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
```

---

## Step 2 — Docker Compose

Create `docker-compose.yml`:
```yaml
version: '3.9'

services:
  # Laravel application (PHP-FPM)
  app:
    build: { context: ., target: runtime }
    env_file: .env
    volumes:
      - app_storage:/var/www/html/storage
    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_started
    restart: unless-stopped
    deploy:
      replicas: 2  # horizontal scaling — Nginx load-balances between instances

  # Laravel Horizon (queue workers)
  horizon:
    build: { context: ., target: runtime }
    command: php artisan horizon
    env_file: .env
    depends_on: [db, redis]
    restart: unless-stopped

  # Laravel Reverb (WebSocket server)
  # NOTE: Redis pub/sub backend is enabled here for multi-instance support.
  # In .env set REVERB_SCALING_ENABLED=true when running >1 Reverb instance.
  reverb:
    build: { context: ., target: runtime }
    command: php artisan reverb:start --host=0.0.0.0 --port=8080
    env_file: .env
    depends_on: [redis]
    restart: unless-stopped
    expose: ["8080"]
    deploy:
      replicas: 2  # Nginx uses ip_hash for sticky sessions

  # Laravel Scheduler (cron)
  # Only ONE instance should run the scheduler — use single-server schedule locks
  scheduler:
    build: { context: ., target: runtime }
    command: php artisan schedule:work
    env_file: .env
    depends_on: [db, redis]
    restart: unless-stopped

  # PostgreSQL 16
  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB:       ${DB_DATABASE}
      POSTGRES_USER:     ${DB_USERNAME}
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - postgres_data:/var/lib/postgresql/data
    restart: unless-stopped
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${DB_USERNAME}"]
      interval: 5s
      timeout: 5s
      retries: 5

  # Redis 7
  redis:
    image: redis:7-alpine
    command: redis-server --maxmemory 512mb --maxmemory-policy allkeys-lru
    volumes:
      - redis_data:/data
    restart: unless-stopped

  # Meilisearch
  meilisearch:
    image: getmeili/meilisearch:v1.7
    environment:
      MEILI_MASTER_KEY: ${MEILISEARCH_KEY}
    volumes:
      - meilisearch_data:/meili_data
    restart: unless-stopped

  # Nginx reverse proxy
  nginx:
    image: nginx:alpine
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./docker/nginx.conf:/etc/nginx/nginx.conf:ro
      - ./public:/var/www/html/public:ro
      - ./docker/ssl:/etc/nginx/ssl:ro
    depends_on: [app, reverb]
    restart: unless-stopped

volumes:
  postgres_data:
  redis_data:
  meilisearch_data:
  app_storage:
```

---

## Step 3 — Nginx configuration

Create `docker/nginx.conf`:
```nginx
events {
    worker_connections 1024;
}

http {
    include       /etc/nginx/mime.types;
    default_type  application/octet-stream;
    sendfile      on;

    upstream php_fpm {
        server app:9000;
    }

    # ip_hash ensures WebSocket connections from the same client
    # always reach the same Reverb instance
    upstream reverb_ws {
        ip_hash;
        server reverb:8080;
    }

    server {
        listen 80;
        server_name _;
        return 301 https://$host$request_uri;
    }

    server {
        listen      443 ssl;
        server_name your-district-domain.org;

        ssl_certificate     /etc/nginx/ssl/cert.pem;
        ssl_certificate_key /etc/nginx/ssl/key.pem;
        ssl_protocols       TLSv1.2 TLSv1.3;

        root  /var/www/html/public;
        index index.php;

        # Reverb WebSocket endpoint — must come before the general PHP block
        location /app/ {
            proxy_pass         http://reverb_ws;
            proxy_http_version 1.1;
            proxy_set_header   Upgrade $http_upgrade;
            proxy_set_header   Connection "upgrade";
            proxy_set_header   Host $host;
            proxy_read_timeout 3600; # Keep WebSocket connections alive
        }

        # General routing
        location / {
            try_files $uri $uri/ /index.php?$query_string;
        }

        # PHP-FPM
        location ~ \.php$ {
            fastcgi_pass   php_fpm;
            fastcgi_index  index.php;
            fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include        fastcgi_params;
            fastcgi_read_timeout 300; # Allow for long SSE streams
        }

        # Static assets — long cache, immutable
        location ~* \.(js|css|png|jpg|webp|ico|woff2|svg)$ {
            expires 1y;
            add_header Cache-Control "public, immutable";
        }

        # Block .env and hidden files
        location ~ /\. {
            deny all;
        }
    }
}
```

---

## Step 4 — Production .env

Create `.env.production.example`:
```bash
APP_NAME=ATLAAS
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-district-domain.org

# Generate with: php artisan key:generate --show
APP_KEY=

DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=atlaas
DB_USERNAME=atlaas
DB_PASSWORD=CHANGE_THIS_TO_STRONG_PASSWORD

REDIS_HOST=redis
REDIS_PORT=6379

# Your district's AI server — swap for any OpenAI-compatible endpoint
OPENAI_BASE_URL=http://your-ai-server:11434/v1
OPENAI_API_KEY=CHANGE_THIS
OPENAI_MODEL=llama3.2

QUEUE_CONNECTION=redis
BROADCAST_DRIVER=reverb

REVERB_APP_ID=atlaas
REVERB_APP_KEY=CHANGE_THIS_RANDOM_STRING
REVERB_APP_SECRET=CHANGE_THIS_RANDOM_STRING
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
REVERB_SCHEME=https
REVERB_SCALING_ENABLED=true  # Enables Redis pub/sub between Reverb instances

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="your-district-domain.org"
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https

MEILISEARCH_HOST=http://meilisearch:7700
MEILISEARCH_KEY=CHANGE_THIS

MAIL_MAILER=smtp
MAIL_HOST=smtp.your-district-mail.org
MAIL_PORT=587
MAIL_USERNAME=noreply@your-district.org
MAIL_PASSWORD=CHANGE_THIS
MAIL_FROM_ADDRESS=noreply@your-district.org
MAIL_FROM_NAME=ATLAAS

GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=${APP_URL}/auth/google/callback
```

---

## Step 5 — First-run setup command

Create `app/Console/Commands/CreateDistrictAdmin.php`:
```php
<?php

namespace App\Console\Commands;

use App\Models\District;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateDistrictAdmin extends Command
{
    protected $signature = 'atlaas:create-admin
        {--email= : Admin email address}
        {--name= : Admin full name}
        {--district= : District name}';

    protected $description = 'Create the first district and admin account';

    public function handle(): int
    {
        $email        = $this->option('email') ?? $this->ask('Admin email');
        $name         = $this->option('name')  ?? $this->ask('Admin full name');
        $districtName = $this->option('district') ?? $this->ask('District name');

        $district = District::firstOrCreate(
            ['slug' => \Illuminate\Support\Str::slug($districtName)],
            ['name' => $districtName]
        );

        $password = \Illuminate\Support\Str::password(16);

        $user = User::create([
            'district_id' => $district->id,
            'name'        => $name,
            'email'       => $email,
            'password'    => Hash::make($password),
        ]);
        $user->assignRole('district_admin');

        $this->info("District admin created successfully.");
        $this->line("Email: {$email}");
        $this->line("Temporary password: {$password}");
        $this->warn("Change this password immediately after first login.");

        return self::SUCCESS;
    }
}
```

---

## Step 6 — Setup and backup scripts

### `scripts/setup.sh`
```bash
#!/bin/bash
set -e

echo "=== ATLAAS First-Run Setup ==="

echo "Generating application key..."
docker compose exec app php artisan key:generate

echo "Running database migrations..."
docker compose exec app php artisan migrate --force

echo "Seeding roles and built-in tools..."
docker compose exec app php artisan db:seed --class=RolesAndPermissionsSeeder
docker compose exec app php artisan db:seed --class=BuiltInToolsSeeder

echo "Creating storage link..."
docker compose exec app php artisan storage:link

echo "Indexing spaces for search..."
docker compose exec app php artisan scout:import "App\\Models\\LearningSpace"

echo "Creating first district admin..."
docker compose exec app php artisan atlaas:create-admin

echo ""
echo "=== Setup complete ==="
echo "Visit https://your-district-domain.org to get started."
echo "Horizon: https://your-district-domain.org/horizon (district admin only)"
```

### `scripts/backup.sh`
```bash
#!/bin/bash
set -e

DATE=$(date +%Y%m%d_%H%M)
BACKUP_DIR="/backups/atlaas"
mkdir -p "$BACKUP_DIR"

echo "Backing up database..."
docker compose exec -T db pg_dump \
    -U "$DB_USERNAME" "$DB_DATABASE" \
    | gzip > "$BACKUP_DIR/db_${DATE}.sql.gz"

echo "Backup saved to: $BACKUP_DIR/db_${DATE}.sql.gz"

# Retain last 30 days of backups
find "$BACKUP_DIR" -name "db_*.sql.gz" -mtime +30 -delete

echo "Old backups pruned. Done."
```

Make both executable:
```bash
chmod +x scripts/setup.sh scripts/backup.sh
```

---

## Step 7 — Launch

```bash
# Copy and fill in your production env
cp .env.production.example .env
# Edit .env — fill in passwords, domain, LLM endpoint

# Build and start all services
docker compose up -d --build

# Wait for DB to be healthy
docker compose ps

# Run first-time setup
./scripts/setup.sh
```

---

## Step 8 — Final system checklist

Run all of these after deployment.

**Infrastructure:**
- [ ] `docker compose ps` — all containers show "Up" or "healthy"
- [ ] `docker compose logs nginx` — no errors
- [ ] `docker compose logs app` — no PHP-FPM errors
- [ ] HTTPS loads at your domain (no certificate warnings)

**Auth:**
- [ ] District admin can log in
- [ ] Google OAuth redirect works and returns to the app
- [ ] Student with a wrong district email is blocked at login

**Core features:**
- [ ] Teacher can create a classroom and space
- [ ] Student can join with a code and start a session
- [ ] ATLAAS responds in real time (SSE streaming)
- [ ] Safety phrase → alert created → teacher email sent (check mail logs)
- [ ] Teacher's Compass View updates live when student sends a message
- [ ] Toolkit tools stream output correctly
- [ ] Discover search returns results

**Operations:**
- [ ] `/horizon` accessible as district admin
- [ ] Horizon shows all queues running (critical, high, default, low)
- [ ] `./scripts/backup.sh` creates a `.sql.gz` file
- [ ] `docker compose restart app` → app comes back without data loss

---

## ATLAAS complete.

| Phase | Builds                        | Done when                                          |
|-------|-------------------------------|----------------------------------------------------|
| 1     | Scaffold, auth, roles         | Teacher and student log in to separate dashboards  |
| 2     | Classrooms, Spaces, Sessions  | Teacher creates a space; student enrolls           |
| 3     | LLM adapter, safety, SSE chat | Student chats with ATLAAS in real time            |
| 4     | Horizon, alerts, summaries    | Alerts in DB; summaries generated after sessions   |
| 5     | Reverb, Compass View          | Teacher watches sessions update live               |
| 6     | Teacher Toolkit               | Teacher runs AI tools with streaming output        |
| 7     | Discover library, Meilisearch | Teachers publish, search, and import spaces        |
| 8     | Docker, Nginx, deployment     | Entire app runs with `docker compose up`           |

Do not start the next phase until every checklist item in the current phase passes.

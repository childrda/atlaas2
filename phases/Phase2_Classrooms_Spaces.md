# ATLAAS — Phase 2: Classrooms, Spaces & Sessions
## Prerequisite: Phase 1 checklist fully passing
## Stop when this works: A teacher can create a Space and a student can enroll and see it

---

## What you're building in this phase
- Classrooms (teacher-owned, students enroll via join code)
- Learning Spaces (AI lesson environments — no AI yet, just the data)
- Student Sessions and Messages tables (ready for Phase 3 AI)
- District global scope applied to all new models
- CRUD pages for Classrooms and Spaces
- Student enrollment via join code

**No AI, no real-time. Data layer and basic UI only.**

---

## Step 1 — Join code helper

Create `app/Helpers/JoinCode.php` before any models, since models use it in their boot:
```php
<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;

class JoinCode
{
    public static function generate(string $table, string $column = 'join_code', int $length = 6): string
    {
        do {
            // Exclude characters that look alike: 0/O, 1/I/L
            $code = strtoupper(substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZ23456789'), 0, $length));
        } while (DB::table($table)->where($column, $code)->exists());

        return $code;
    }
}
```

---

## Step 2 — Migrations (run in this order)

### 2a. Classrooms
```php
Schema::create('classrooms', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('district_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('teacher_id')->constrained('users')->cascadeOnDelete();
    $table->string('name');
    $table->string('subject')->nullable();
    $table->string('grade_level')->nullable();
    $table->string('join_code', 8)->unique();
    $table->string('external_id')->nullable(); // Clever section ID for Phase 8+
    $table->timestamps();
    $table->softDeletes();
    $table->index(['district_id', 'teacher_id']);
});
```

### 2b. Classroom enrollment pivot
```php
Schema::create('classroom_student', function (Blueprint $table) {
    $table->foreignUuid('classroom_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('student_id')->constrained('users')->cascadeOnDelete();
    $table->timestamp('enrolled_at')->useCurrent();
    $table->primary(['classroom_id', 'student_id']);
});
```

### 2c. Learning spaces
```php
Schema::create('learning_spaces', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('district_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('teacher_id')->constrained('users')->cascadeOnDelete();
    $table->foreignUuid('classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();
    $table->string('title');
    $table->text('description')->nullable();
    $table->string('subject')->nullable();
    $table->string('grade_level')->nullable();
    $table->string('cover_image')->nullable();
    $table->text('system_prompt')->nullable();
    $table->jsonb('goals')->default('[]');
    $table->jsonb('restrictions')->default('{}');
    $table->jsonb('allowed_tools')->default('[]');
    $table->string('atlaas_tone')->default('encouraging'); // encouraging|socratic|direct|playful
    $table->string('language', 10)->default('en');
    $table->integer('max_messages')->nullable();
    $table->boolean('require_teacher_present')->default(false);
    $table->boolean('allow_session_restart')->default(true);
    $table->boolean('is_published')->default(false);
    $table->boolean('is_public')->default(false);
    $table->boolean('is_archived')->default(false);
    $table->string('join_code', 8)->unique();
    $table->timestamp('opens_at')->nullable();
    $table->timestamp('closes_at')->nullable();
    $table->timestamps();
    $table->softDeletes();
    $table->index(['district_id', 'is_archived']);
    $table->index(['teacher_id', 'is_archived']);
});
```

### 2d. Student sessions
```php
Schema::create('student_sessions', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('district_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('student_id')->constrained('users')->cascadeOnDelete();
    $table->foreignUuid('space_id')->constrained('learning_spaces')->cascadeOnDelete();
    $table->string('status')->default('active'); // active|completed|flagged|abandoned
    $table->integer('message_count')->default(0);
    $table->integer('tokens_used')->default(0);
    $table->text('student_summary')->nullable();
    $table->text('teacher_summary')->nullable();
    $table->timestamp('started_at')->useCurrent();
    $table->timestamp('ended_at')->nullable();
    $table->timestamps();
    $table->index(['space_id', 'status']);
    $table->index(['student_id', 'started_at']);
    $table->index(['district_id', 'started_at']); // admin reporting queries
});
```

### 2e. Messages
```php
Schema::create('messages', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('session_id')->constrained('student_sessions')->cascadeOnDelete();
    $table->foreignUuid('district_id')->constrained()->cascadeOnDelete();
    $table->string('role'); // user|assistant|system|teacher_inject
    $table->text('content');
    $table->boolean('flagged')->default(false);
    $table->string('flag_reason')->nullable();
    $table->string('flag_category')->nullable();
    $table->integer('tokens')->default(0);
    $table->timestamps();
    $table->index(['session_id', 'created_at']);
    $table->index(['district_id', 'flagged']);
});
```

```bash
php artisan migrate
```

---

## Step 3 — Models

All models extend `BaseModel` and include the district global scope.

### `app/Models/Classroom.php`
```php
<?php

namespace App\Models;

use App\Helpers\JoinCode;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Classroom extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'district_id', 'school_id', 'teacher_id',
        'name', 'subject', 'grade_level', 'join_code', 'external_id',
    ];

    protected static function booted(): void
    {
        parent::booted();

        // Auto-generate join code on create
        static::creating(function (Classroom $classroom) {
            if (empty($classroom->join_code)) {
                $classroom->join_code = JoinCode::generate('classrooms');
            }
        });

        // Scope all queries to the authenticated user's district
        static::addGlobalScope('district', function ($query) {
            if (auth()->check()) {
                $query->where('classrooms.district_id', auth()->user()->district_id);
            }
        });
    }

    public function district(): BelongsTo  { return $this->belongsTo(District::class); }
    public function school(): BelongsTo    { return $this->belongsTo(School::class); }
    public function teacher(): BelongsTo   { return $this->belongsTo(User::class, 'teacher_id'); }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'classroom_student', 'classroom_id', 'student_id')
                    ->withPivot('enrolled_at');
    }

    public function spaces(): HasMany { return $this->hasMany(LearningSpace::class); }
}
```

### `app/Models/LearningSpace.php`
```php
<?php

namespace App\Models;

use App\Helpers\JoinCode;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LearningSpace extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'district_id', 'teacher_id', 'classroom_id', 'title', 'description',
        'subject', 'grade_level', 'cover_image', 'system_prompt', 'goals',
        'restrictions', 'allowed_tools', 'atlaas_tone', 'language',
        'max_messages', 'require_teacher_present', 'allow_session_restart',
        'is_published', 'is_public', 'is_archived', 'join_code', 'opens_at', 'closes_at',
    ];

    protected function casts(): array
    {
        return [
            'goals'                   => 'array',
            'restrictions'            => 'array',
            'allowed_tools'           => 'array',
            'is_published'            => 'boolean',
            'is_public'               => 'boolean',
            'is_archived'             => 'boolean',
            'require_teacher_present' => 'boolean',
            'allow_session_restart'   => 'boolean',
            'opens_at'                => 'datetime',
            'closes_at'               => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (LearningSpace $space) {
            if (empty($space->join_code)) {
                $space->join_code = JoinCode::generate('learning_spaces');
            }
        });

        static::addGlobalScope('district', function ($query) {
            if (auth()->check()) {
                $query->where('learning_spaces.district_id', auth()->user()->district_id);
            }
        });
    }

    public function district(): BelongsTo  { return $this->belongsTo(District::class); }
    public function teacher(): BelongsTo   { return $this->belongsTo(User::class, 'teacher_id'); }
    public function classroom(): BelongsTo { return $this->belongsTo(Classroom::class); }
    public function sessions(): HasMany    { return $this->hasMany(StudentSession::class, 'space_id'); }
}
```

### `app/Models/StudentSession.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentSession extends BaseModel
{
    protected $fillable = [
        'district_id', 'student_id', 'space_id', 'status',
        'message_count', 'tokens_used', 'student_summary', 'teacher_summary',
        'started_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at'   => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('district', function ($query) {
            if (auth()->check()) {
                $query->where('student_sessions.district_id', auth()->user()->district_id);
            }
        });
    }

    public function student(): BelongsTo { return $this->belongsTo(User::class, 'student_id'); }
    public function space(): BelongsTo   { return $this->belongsTo(LearningSpace::class, 'space_id'); }
    public function messages(): HasMany  { return $this->hasMany(Message::class, 'session_id'); }
}
```

### `app/Models/Message.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends BaseModel
{
    protected $fillable = [
        'session_id', 'district_id', 'role', 'content',
        'flagged', 'flag_reason', 'flag_category', 'tokens',
    ];

    protected function casts(): array
    {
        return ['flagged' => 'boolean'];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('district', function ($query) {
            if (auth()->check()) {
                $query->where('messages.district_id', auth()->user()->district_id);
            }
        });
    }

    public function session(): BelongsTo { return $this->belongsTo(StudentSession::class, 'session_id'); }
}
```

---

## Step 4 — Policies

### `app/Policies/ClassroomPolicy.php`
```php
<?php

namespace App\Policies;

use App\Models\Classroom;
use App\Models\User;

class ClassroomPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['teacher', 'school_admin', 'district_admin']);
    }

    public function view(User $user, Classroom $classroom): bool
    {
        return $classroom->teacher_id === $user->id
            || $user->hasRole(['school_admin', 'district_admin']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['teacher', 'school_admin', 'district_admin']);
    }

    public function update(User $user, Classroom $classroom): bool
    {
        return $classroom->teacher_id === $user->id
            || $user->hasRole(['school_admin', 'district_admin']);
    }

    public function delete(User $user, Classroom $classroom): bool
    {
        return $this->update($user, $classroom);
    }
}
```

### `app/Policies/LearningSpacePolicy.php`
```php
<?php

namespace App\Policies;

use App\Models\LearningSpace;
use App\Models\User;

class LearningSpacePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['teacher', 'school_admin', 'district_admin']);
    }

    public function view(User $user, LearningSpace $space): bool
    {
        return $space->teacher_id === $user->id
            || $user->hasRole(['school_admin', 'district_admin']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['teacher', 'school_admin', 'district_admin']);
    }

    public function update(User $user, LearningSpace $space): bool
    {
        return $space->teacher_id === $user->id
            || $user->hasRole(['school_admin', 'district_admin']);
    }

    public function delete(User $user, LearningSpace $space): bool
    {
        return $this->update($user, $space);
    }
}
```

Register both in `app/Providers/AuthServiceProvider.php`:
```php
protected $policies = [
    Classroom::class    => ClassroomPolicy::class,
    LearningSpace::class => LearningSpacePolicy::class,
];
```

---

## Step 5 — Classroom controller

`app/Http/Controllers/Teacher/ClassroomController.php`:
```php
<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClassroomController extends Controller
{
    public function index(): Response
    {
        $classrooms = Classroom::where('teacher_id', auth()->id())
            ->withCount('students')
            ->latest()
            ->paginate(20);

        return Inertia::render('Teacher/Classrooms/Index', [
            'classrooms' => $classrooms,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'subject'     => 'nullable|string|max:100',
            'grade_level' => 'nullable|string|max:20',
        ]);

        $classroom = Classroom::create([
            ...$data,
            'district_id' => auth()->user()->district_id,
            'school_id'   => auth()->user()->school_id,
            'teacher_id'  => auth()->id(),
        ]);

        return redirect()->route('teacher.classrooms.show', $classroom)
            ->with('success', 'Classroom created.');
    }

    public function show(Classroom $classroom): Response
    {
        $this->authorize('view', $classroom);

        return Inertia::render('Teacher/Classrooms/Show', [
            'classroom' => $classroom->load('students', 'spaces'),
        ]);
    }

    public function update(Request $request, Classroom $classroom): RedirectResponse
    {
        $this->authorize('update', $classroom);

        $classroom->update($request->validate([
            'name'        => 'required|string|max:100',
            'subject'     => 'nullable|string|max:100',
            'grade_level' => 'nullable|string|max:20',
        ]));

        return back()->with('success', 'Classroom updated.');
    }

    public function destroy(Classroom $classroom): RedirectResponse
    {
        $this->authorize('delete', $classroom);
        $classroom->delete();

        return redirect()->route('teacher.classrooms.index')
            ->with('success', 'Classroom archived.');
    }

    public function addStudent(Request $request, Classroom $classroom): RedirectResponse
    {
        $this->authorize('update', $classroom);

        $data = $request->validate(['email' => 'required|email']);

        $student = User::where('email', $data['email'])
            ->where('district_id', auth()->user()->district_id)
            ->whereHas('roles', fn($q) => $q->where('name', 'student'))
            ->first();

        if (!$student) {
            return back()->withErrors(['email' => 'No student found with that email in your district.']);
        }

        $classroom->students()->syncWithoutDetaching([
            $student->id => ['enrolled_at' => now()],
        ]);

        return back()->with('success', "{$student->name} added to classroom.");
    }

    public function removeStudent(Classroom $classroom, User $student): RedirectResponse
    {
        $this->authorize('update', $classroom);
        $classroom->students()->detach($student->id);

        return back()->with('success', 'Student removed from classroom.');
    }
}
```

---

## Step 6 — Space controller

`app/Http/Controllers/Teacher/SpaceController.php`:
```php
<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Helpers\JoinCode;
use App\Models\Classroom;
use App\Models\LearningSpace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SpaceController extends Controller
{
    public function index(): Response
    {
        $spaces = LearningSpace::where('teacher_id', auth()->id())
            ->where('is_archived', false)
            ->withCount('sessions')
            ->latest()
            ->paginate(20);

        return Inertia::render('Teacher/Spaces/Index', ['spaces' => $spaces]);
    }

    public function create(): Response
    {
        return Inertia::render('Teacher/Spaces/Create', [
            'classrooms' => Classroom::where('teacher_id', auth()->id())
                ->get(['id', 'name', 'grade_level', 'subject']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title'         => 'required|string|max:150',
            'description'   => 'nullable|string|max:1000',
            'subject'       => 'nullable|string|max:100',
            'grade_level'   => 'nullable|string|max:20',
            'classroom_id'  => 'nullable|uuid|exists:classrooms,id',
            'system_prompt' => 'nullable|string|max:4000',
            'goals'         => 'nullable|array|max:5',
            'goals.*'       => 'string|max:200',
            'atlaas_tone'  => 'in:encouraging,socratic,direct,playful',
            'language'      => 'string|max:10',
            'max_messages'  => 'nullable|integer|min:5|max:500',
        ]);

        $space = LearningSpace::create([
            ...$data,
            'district_id' => auth()->user()->district_id,
            'teacher_id'  => auth()->id(),
            'goals'       => $data['goals'] ?? [],
        ]);

        return redirect()->route('teacher.spaces.show', $space)
            ->with('success', 'Space created. Configure it before sharing the join code.');
    }

    public function show(LearningSpace $space): Response
    {
        $this->authorize('view', $space);

        return Inertia::render('Teacher/Spaces/Show', [
            'space'          => $space->load('classroom'),
            'recentSessions' => $space->sessions()
                ->with('student:id,name')
                ->latest('started_at')
                ->limit(10)
                ->get(),
            'sessionCount'   => $space->sessions()->count(),
        ]);
    }

    public function edit(LearningSpace $space): Response
    {
        $this->authorize('update', $space);

        return Inertia::render('Teacher/Spaces/Edit', [
            'space'      => $space,
            'classrooms' => Classroom::where('teacher_id', auth()->id())
                ->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, LearningSpace $space): RedirectResponse
    {
        $this->authorize('update', $space);

        $space->update($request->validate([
            'title'         => 'required|string|max:150',
            'description'   => 'nullable|string|max:1000',
            'subject'       => 'nullable|string|max:100',
            'grade_level'   => 'nullable|string|max:20',
            'classroom_id'  => 'nullable|uuid|exists:classrooms,id',
            'system_prompt' => 'nullable|string|max:4000',
            'goals'         => 'nullable|array|max:5',
            'goals.*'       => 'string|max:200',
            'atlaas_tone'  => 'in:encouraging,socratic,direct,playful',
            'language'      => 'string|max:10',
            'max_messages'  => 'nullable|integer|min:5|max:500',
        ]));

        return back()->with('success', 'Space updated.');
    }

    public function publish(Request $request, LearningSpace $space): RedirectResponse
    {
        $this->authorize('update', $space);

        $space->update(['is_published' => !$space->is_published]);

        $label = $space->is_published ? 'published' : 'unpublished';
        return back()->with('success', "Space {$label}.");
    }

    public function duplicate(LearningSpace $space): RedirectResponse
    {
        $this->authorize('view', $space);

        $copy = $space->replicate(['join_code', 'is_public', 'classroom_id', 'is_published']);
        $copy->title      = $space->title . ' (Copy)';
        $copy->teacher_id = auth()->id();
        $copy->join_code  = JoinCode::generate('learning_spaces');
        $copy->save();

        return redirect()->route('teacher.spaces.edit', $copy)
            ->with('success', 'Space duplicated. Edit it before publishing.');
    }

    public function destroy(LearningSpace $space): RedirectResponse
    {
        $this->authorize('delete', $space);
        $space->update(['is_archived' => true]);

        return redirect()->route('teacher.spaces.index')
            ->with('success', 'Space archived.');
    }
}
```

---

## Step 7 — Student join controller

`app/Http/Controllers/Student/StudentJoinController.php`:
```php
<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\LearningSpace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudentJoinController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Student/Join');
    }

    public function join(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => 'required|string|max:10']);
        $code = strtoupper(trim($data['code']));

        // Try classroom first
        $classroom = Classroom::withoutGlobalScope('district')
            ->where('join_code', $code)
            ->where('district_id', auth()->user()->district_id) // manual scope for safety
            ->first();

        if ($classroom) {
            $classroom->students()->syncWithoutDetaching([
                auth()->id() => ['enrolled_at' => now()],
            ]);
            return redirect()->route('student.dashboard')
                ->with('success', "Joined {$classroom->name}!");
        }

        // Try space
        $space = LearningSpace::withoutGlobalScope('district')
            ->where('join_code', $code)
            ->where('district_id', auth()->user()->district_id) // manual scope for safety
            ->where('is_published', true)
            ->where('is_archived', false)
            ->first();

        if ($space) {
            return redirect()->route('student.spaces.show', $space);
        }

        return back()->withErrors(['code' => 'Code not found. Check with your teacher.']);
    }
}
```

> **Note on `withoutGlobalScope`:** The global district scope uses `auth()->user()->district_id`,
> which is correct. But join flows arrive from outside a typical scoped query, so we use
> `withoutGlobalScope` then manually add the district filter — the result is identical but
> the intent is explicit and safe.

---

## Step 8 — Update routes

Add to `routes/web.php` inside the appropriate groups:

```php
// In teacher route group:
Route::get('classrooms', [ClassroomController::class, 'index'])->name('classrooms.index');
Route::post('classrooms', [ClassroomController::class, 'store'])->name('classrooms.store');
Route::get('classrooms/{classroom}', [ClassroomController::class, 'show'])->name('classrooms.show');
Route::patch('classrooms/{classroom}', [ClassroomController::class, 'update'])->name('classrooms.update');
Route::delete('classrooms/{classroom}', [ClassroomController::class, 'destroy'])->name('classrooms.destroy');
Route::post('classrooms/{classroom}/students', [ClassroomController::class, 'addStudent'])->name('classrooms.students.add');
Route::delete('classrooms/{classroom}/students/{student}', [ClassroomController::class, 'removeStudent'])->name('classrooms.students.remove');

Route::get('spaces', [SpaceController::class, 'index'])->name('spaces.index');
Route::get('spaces/create', [SpaceController::class, 'create'])->name('spaces.create');
Route::post('spaces', [SpaceController::class, 'store'])->name('spaces.store');
Route::get('spaces/{space}', [SpaceController::class, 'show'])->name('spaces.show');
Route::get('spaces/{space}/edit', [SpaceController::class, 'edit'])->name('spaces.edit');
Route::patch('spaces/{space}', [SpaceController::class, 'update'])->name('spaces.update');
Route::post('spaces/{space}/publish', [SpaceController::class, 'publish'])->name('spaces.publish');
Route::post('spaces/{space}/duplicate', [SpaceController::class, 'duplicate'])->name('spaces.duplicate');
Route::delete('spaces/{space}', [SpaceController::class, 'destroy'])->name('spaces.destroy');

// In student route group:
Route::get('join', [StudentJoinController::class, 'show'])->name('join.show');
Route::post('join', [StudentJoinController::class, 'join'])->name('join');
Route::get('spaces', [StudentSpaceController::class, 'index'])->name('spaces.index');
Route::get('spaces/{space}', [StudentSpaceController::class, 'show'])->name('spaces.show');
```

---

## Step 9 — Student space controller (stub)

`app/Http/Controllers/Student/StudentSpaceController.php`:
```php
<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\LearningSpace;
use Inertia\Inertia;
use Inertia\Response;

class StudentSpaceController extends Controller
{
    public function index(): Response
    {
        // Spaces from classrooms the student is enrolled in
        $spaces = LearningSpace::withoutGlobalScope('district')
            ->where('learning_spaces.district_id', auth()->user()->district_id)
            ->where('is_published', true)
            ->where('is_archived', false)
            ->whereHas('classroom.students', fn($q) => $q->where('users.id', auth()->id()))
            ->with('teacher:id,name')
            ->get();

        return Inertia::render('Student/Spaces/Index', ['spaces' => $spaces]);
    }

    public function show(LearningSpace $space): Response
    {
        // Phase 3 will add session start here
        return Inertia::render('Student/Spaces/Show', [
            'space' => $space->load('teacher:id,name'),
        ]);
    }
}
```

---

## Step 10 — Update test data seeder

Add to `TestDataSeeder::run()`:
```php
use App\Models\Classroom;
use App\Models\LearningSpace;

// Add classroom
$classroom = Classroom::create([
    'district_id' => $district->id,
    'school_id'   => $school->id,
    'teacher_id'  => $teacher->id,
    'name'        => 'Grade 5 Science',
    'subject'     => 'Science',
    'grade_level' => '5',
]);

$classroom->students()->attach($student->id, ['enrolled_at' => now()]);

// Add a sample space
LearningSpace::create([
    'district_id'   => $district->id,
    'teacher_id'    => $teacher->id,
    'classroom_id'  => $classroom->id,
    'title'         => 'The Water Cycle',
    'description'   => 'Explore how water moves through the environment.',
    'subject'       => 'Science',
    'grade_level'   => '5',
    'system_prompt' => 'You are ATLAAS, a friendly science tutor. Help the student understand the water cycle using questions and examples. Do not just give answers — guide them to discover.',
    'goals'         => ['Explain evaporation', 'Explain condensation', 'Describe precipitation'],
    'atlaas_tone'  => 'encouraging',
    'is_published'  => true,
]);
```

```bash
php artisan migrate:fresh --seed
```

---

## Step 11 — React pages

### `resources/js/types/models.ts` additions
```typescript
export interface Classroom {
    id: string;
    name: string;
    subject: string | null;
    grade_level: string | null;
    join_code: string;
    students_count?: number;
}

export interface LearningSpace {
    id: string;
    title: string;
    description: string | null;
    subject: string | null;
    grade_level: string | null;
    join_code: string;
    atlaas_tone: string;
    is_published: boolean;
    is_archived: boolean;
    goals: string[];
    sessions_count?: number;
}
```

### Pages to build:

**`Teacher/Classrooms/Index.tsx`**
- Grid of classroom cards (name, subject, grade, student count, join code pill)
- "New Classroom" button → modal with name, subject, grade level fields
- Each card links to classroom detail

**`Teacher/Classrooms/Show.tsx`**
- Classroom name + join code (large, copy-to-clipboard button)
- Student roster table: name, email, enrolled date, remove button
- "Add student by email" input at bottom of roster
- List of spaces assigned to this classroom

**`Teacher/Spaces/Index.tsx`**
- Grid of space cards: title, subject, grade, join code, session count, published badge
- "New Space" button → `/teach/spaces/create`

**`Teacher/Spaces/Create.tsx`**
Single-page form:
- Title, description, subject, grade level
- Classroom assignment dropdown
- "Instructions for ATLAAS" textarea (system prompt)
- Goals: up to 5 text inputs with add/remove
- Tone: radio group (Encouraging / Socratic / Direct / Playful)
- Max messages: optional number input

**`Teacher/Spaces/Show.tsx`**
- Title, join code (large, copyable), published toggle button
- Goals list
- Recent sessions table (student name, started, status, message count)
- Edit and Archive buttons

**`Student/Dashboard.tsx`** — update to add:
- "Join a Space or Classroom" input with submit
- List of enrolled spaces (links to space detail)

**`Student/Spaces/Index.tsx`**
- Cards for each available space: title, subject, teacher name, "Start" button

**`Student/Spaces/Show.tsx`**
- Space title, description, goals list
- "Start Session" button (Phase 3 will make this functional)
- "Session will be powered by ATLAAS" note

---

## Step 12 — Verify

```bash
php artisan migrate:fresh --seed
npm run dev
php artisan serve
```

**Checklist — do not move to Phase 3 until all pass:**

Teacher:
- [ ] Can create a classroom and see its join code
- [ ] Can add a student to a classroom by email
- [ ] Can create a Learning Space and assign it to a classroom
- [ ] Can publish/unpublish a Space
- [ ] Can duplicate a Space (new join code generated)
- [ ] Cannot see another teacher's spaces (global scope working)

Student:
- [ ] Dashboard shows enrolled spaces
- [ ] Can enter a classroom join code → enrolled + redirected to dashboard
- [ ] Can enter a space join code → redirected to space detail page
- [ ] Space detail shows goals and a "Start Session" button (non-functional yet)

Policy:
- [ ] Student visiting `/teach/spaces` → 403
- [ ] Teacher visiting another teacher's space URL directly → 403

District scope:
- [ ] Open `php artisan tinker`
- [ ] `App\Models\LearningSpace::toBase()->toSql()` — confirm `district_id` appears in the WHERE clause

---

## Phase 2 complete. Next: Phase 3 — LLM adapter, safety filter, and student chat with SSE streaming.

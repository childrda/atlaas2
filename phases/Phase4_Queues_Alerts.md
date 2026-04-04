# ATLAAS — Phase 4: Queues, Safety Alerts & Session Summaries
## Prerequisite: Phase 3 checklist fully passing
## Stop when this works: Safety alerts are stored in the DB and session summaries are generated on session end

---

## What you're building in this phase
- Laravel Horizon (queue monitor + worker management)
- SafetyAlert model and migration
- ProcessSafetyAlert job: creates DB record, emails teacher on critical alerts
- GenerateSessionSummary job: runs when a session ends, produces student + teacher summaries
- Teacher alert list page
- Student summary display on dashboard

**No WebSockets yet. Alerts are stored and visible on page refresh. Real-time push comes in Phase 5.**

---

## Step 1 — Install and configure Horizon

```bash
composer require laravel/horizon
php artisan horizon:install
php artisan vendor:publish --provider="Laravel\Horizon\HorizonServiceProvider"
```

In `.env`:
```
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
```

Start Redis locally if not already running:
```bash
# macOS
brew services start redis

# Linux
sudo systemctl start redis
```

### `config/horizon.php` — queue supervisors
```php
'environments' => [
    'local' => [
        'supervisor-critical' => [
            'connection' => 'redis',
            'queue'      => ['critical', 'high'],
            'processes'  => 3,
            'tries'      => 3,
            'timeout'    => 60,
        ],
        'supervisor-default' => [
            'connection' => 'redis',
            'queue'      => ['default', 'low'],
            'processes'  => 2,
            'tries'      => 3,
            'timeout'    => 120,
        ],
    ],
    'production' => [
        'supervisor-critical' => [
            'connection' => 'redis',
            'queue'      => ['critical', 'high'],
            'processes'  => 10,
            'tries'      => 3,
            'timeout'    => 60,
        ],
        'supervisor-default' => [
            'connection' => 'redis',
            'queue'      => ['default'],
            'processes'  => 10,
            'tries'      => 3,
            'timeout'    => 120,
        ],
        'supervisor-low' => [
            'connection' => 'redis',
            'queue'      => ['low'],
            'processes'  => 5,
            'tries'      => 3,
            'timeout'    => 300,
        ],
    ],
],
```

Lock Horizon dashboard to district admins.
In `app/Providers/HorizonServiceProvider.php`:
```php
protected function gate(): void
{
    Gate::define('viewHorizon', function ($user) {
        return $user->hasRole('district_admin');
    });
}
```

Run Horizon in a second terminal during development:
```bash
php artisan horizon
```

---

## Step 2 — Safety alerts migration and model

### Migration
```php
Schema::create('safety_alerts', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('district_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('school_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignUuid('session_id')->constrained('student_sessions')->cascadeOnDelete();
    $table->foreignUuid('student_id')->constrained('users')->cascadeOnDelete();
    $table->foreignUuid('teacher_id')->constrained('users')->cascadeOnDelete();
    $table->string('severity'); // critical|high|medium|low
    $table->string('category'); // self_harm|abuse_disclosure|bullying|profanity|other
    $table->text('trigger_content'); // encrypted at rest via cast
    $table->string('status')->default('open'); // open|reviewed|resolved|dismissed|escalated
    $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
    $table->text('reviewer_notes')->nullable();
    $table->timestamp('reviewed_at')->nullable();
    $table->timestamps();

    $table->index(['district_id', 'status', 'severity']);
    $table->index(['teacher_id', 'status']);
    $table->index(['session_id']);
});
```

```bash
php artisan migrate
```

### `app/Models/SafetyAlert.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SafetyAlert extends BaseModel
{
    protected $fillable = [
        'district_id', 'school_id', 'session_id', 'student_id', 'teacher_id',
        'severity', 'category', 'trigger_content', 'status',
        'reviewed_by', 'reviewer_notes', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'trigger_content' => 'encrypted', // AES-256 via Laravel's built-in encryption
            'reviewed_at'     => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('district', function ($query) {
            if (auth()->check()) {
                $query->where('safety_alerts.district_id', auth()->user()->district_id);
            }
        });
    }

    public function session(): BelongsTo  { return $this->belongsTo(StudentSession::class); }
    public function student(): BelongsTo  { return $this->belongsTo(User::class, 'student_id'); }
    public function teacher(): BelongsTo  { return $this->belongsTo(User::class, 'teacher_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }

    public function scopeOpen($query)     { return $query->where('status', 'open'); }
    public function scopeCritical($query) { return $query->where('severity', 'critical'); }
}
```

---

## Step 3 — ProcessSafetyAlert job

`app/Jobs/ProcessSafetyAlert.php`:
```php
<?php

namespace App\Jobs;

use App\Mail\SafetyAlertMail;
use App\Models\SafetyAlert;
use App\Models\StudentSession;
use App\Services\AI\FlagResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class ProcessSafetyAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'critical';
    public int    $tries = 3;

    public function __construct(
        private StudentSession $session,
        private FlagResult     $flag,
        private string         $triggerContent,
    ) {}

    public function handle(): void
    {
        $space = $this->session->space;

        $alert = SafetyAlert::create([
            'district_id'     => $this->session->district_id,
            'school_id'       => $space->teacher->school_id,
            'session_id'      => $this->session->id,
            'student_id'      => $this->session->student_id,
            'teacher_id'      => $space->teacher_id,
            'severity'        => $this->flag->severity,
            'category'        => $this->flag->category,
            'trigger_content' => $this->triggerContent, // auto-encrypted by model cast
            'status'          => 'open',
        ]);

        // Email teacher for CRITICAL alerts
        // For HIGH alerts, the teacher sees it in Compass View (Phase 5)
        if ($this->flag->severity === 'critical') {
            Mail::to($space->teacher->email)
                ->send(new SafetyAlertMail($alert->load('student', 'session.space')));
        }

        // Phase 5 adds: AlertFired::dispatch($alert->load('student'));
    }
}
```

### `app/Mail/SafetyAlertMail.php`
```php
<?php

namespace App\Mail;

use App\Models\SafetyAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SafetyAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SafetyAlert $alert) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[ATLAAS] Safety Alert — ' . $this->alert->student->name,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.safety-alert');
    }
}
```

### `resources/views/emails/safety-alert.blade.php`
```blade
<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; max-width: 600px; margin: 40px auto; color: #333;">
    <h2 style="color: #1E3A5F;">ATLAAS Safety Alert</h2>

    <p>A safety concern was detected in one of your Learning Spaces.</p>

    <table style="border-collapse: collapse; width: 100%;">
        <tr>
            <td style="padding: 8px; font-weight: bold; width: 140px;">Student</td>
            <td style="padding: 8px;">{{ $alert->student->name }}</td>
        </tr>
        <tr style="background: #f9f9f9;">
            <td style="padding: 8px; font-weight: bold;">Space</td>
            <td style="padding: 8px;">{{ $alert->session->space->title }}</td>
        </tr>
        <tr>
            <td style="padding: 8px; font-weight: bold;">Category</td>
            <td style="padding: 8px;">{{ ucfirst(str_replace('_', ' ', $alert->category)) }}</td>
        </tr>
        <tr style="background: #f9f9f9;">
            <td style="padding: 8px; font-weight: bold;">Severity</td>
            <td style="padding: 8px; color: #c0392b; font-weight: bold;">{{ strtoupper($alert->severity) }}</td>
        </tr>
        <tr>
            <td style="padding: 8px; font-weight: bold;">Time</td>
            <td style="padding: 8px;">{{ $alert->created_at->format('M j, Y g:i A') }}</td>
        </tr>
    </table>

    <p style="margin-top: 24px;">
        <a href="{{ config('app.url') }}/teach/alerts"
           style="background: #1E3A5F; color: white; padding: 10px 20px; text-decoration: none; border-radius: 6px;">
            Review this alert in ATLAAS
        </a>
    </p>

    <p style="margin-top: 24px; color: #666; font-size: 13px;">
        If this student may be in immediate danger, contact your school administration
        or emergency services directly. Do not rely solely on this system.
    </p>
</body>
</html>
```

For development, use the log mailer so no real email is sent:
```
MAIL_MAILER=log
```
Check `storage/logs/laravel.log` to see email content.

---

## Step 4 — GenerateSessionSummary job

`app/Jobs/GenerateSessionSummary.php`:
```php
<?php

namespace App\Jobs;

use App\Models\StudentSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use OpenAI\Laravel\Facades\OpenAI;

class GenerateSessionSummary implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'default';
    public int    $tries = 2;

    public function __construct(private StudentSession $session) {}

    public function handle(): void
    {
        // Only summarize sessions with meaningful content
        if ($this->session->message_count < 4) return;

        $transcript = $this->session->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->get()
            ->map(fn($m) => ($m->role === 'user' ? 'Student' : 'ATLAAS') . ': ' . $m->content)
            ->join("\n\n");

        // Student-facing: encouraging, second-person
        $studentSummary = OpenAI::chat()->create([
            'model'    => config('openai.model'),
            'messages' => [
                [
                    'role'    => 'system',
                    'content' => 'You write 2-3 sentence learning summaries for K-12 students. ' .
                                 'Use "you" to address the student directly. Be specific about what they explored. ' .
                                 'Be warm and encouraging. Focus on what they did well and learned.',
                ],
                [
                    'role'    => 'user',
                    'content' => "Write a summary of this learning session:\n\n{$transcript}",
                ],
            ],
            'max_tokens' => 150,
        ])->choices[0]->message->content;

        // Teacher-facing: professional, third-person, actionable
        $teacherSummary = OpenAI::chat()->create([
            'model'    => config('openai.model'),
            'messages' => [
                [
                    'role'    => 'system',
                    'content' => 'You write 2-3 sentence session summaries for teachers. ' .
                                 'Cover: what concepts the student engaged with, any signs of confusion or struggle, ' .
                                 'and one suggested next step. Be specific and professional.',
                ],
                [
                    'role'    => 'user',
                    'content' => "Summarize this student session:\n\n{$transcript}",
                ],
            ],
            'max_tokens' => 200,
        ])->choices[0]->message->content;

        $this->session->update([
            'student_summary' => $studentSummary,
            'teacher_summary' => $teacherSummary,
        ]);
    }
}
```

Now uncomment the dispatch line in `SessionController::end()`:
```php
GenerateSessionSummary::dispatch($session);
```

---

## Step 5 — Alert controller

`app/Http/Controllers/Teacher/AlertController.php`:
```php
<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\SafetyAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    public function index(): Response
    {
        $alerts = SafetyAlert::where('teacher_id', auth()->id())
            ->with(['student:id,name', 'session.space:id,title'])
            ->orderByRaw("
                CASE severity
                    WHEN 'critical' THEN 1
                    WHEN 'high'     THEN 2
                    WHEN 'medium'   THEN 3
                    ELSE 4
                END
            ")
            ->orderBy('created_at', 'desc')
            ->paginate(25);

        $openCriticalCount = SafetyAlert::where('teacher_id', auth()->id())
            ->where('status', 'open')
            ->where('severity', 'critical')
            ->count();

        return Inertia::render('Teacher/Alerts/Index', [
            'alerts'            => $alerts,
            'openCriticalCount' => $openCriticalCount,
        ]);
    }

    public function update(Request $request, SafetyAlert $alert): RedirectResponse
    {
        // Teachers can update their own alerts; admins can update any in their district
        abort_unless(
            $alert->teacher_id === auth()->id() || auth()->user()->hasRole(['school_admin', 'district_admin']),
            403
        );

        $data = $request->validate([
            'status'         => 'required|in:reviewed,resolved,dismissed,escalated',
            'reviewer_notes' => 'nullable|string|max:1000',
        ]);

        $alert->update([
            ...$data,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Alert updated.');
    }
}
```

Add routes in teacher group:
```php
Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
Route::patch('alerts/{alert}', [AlertController::class, 'update'])->name('alerts.update');
```

---

## Step 6 — Share open alert count in Inertia

Update `HandleInertiaRequests::share()` to include the alert count for teachers:
```php
'alerts' => [
    'openCount' => auth()->check() && auth()->user()->hasRole(['teacher', 'school_admin', 'district_admin'])
        ? \App\Models\SafetyAlert::where('teacher_id', auth()->id())
              ->where('status', 'open')
              ->count()
        : 0,
],
```

Then in `TeacherLayout.tsx`, show a red badge on the Alerts nav link when count > 0.

---

## Step 7 — React pages

### `resources/js/types/models.ts` additions
```typescript
export interface SafetyAlert {
    id: string;
    severity: 'critical' | 'high' | 'medium' | 'low';
    category: string;
    status: 'open' | 'reviewed' | 'resolved' | 'dismissed' | 'escalated';
    created_at: string;
    student: { id: string; name: string };
    session: { space: { id: string; title: string } };
    reviewer_notes: string | null;
}
```

### `resources/js/Pages/Teacher/Alerts/Index.tsx`
Table layout:
- Columns: Severity badge, Student name, Space, Category, Time, Status, Actions
- Severity badge colors: critical=red, high=orange, medium=yellow, low=gray
- Actions: "Mark Reviewed" / "Escalate" / "Dismiss" buttons (POST to update route)
- Paginator at bottom

Severity badge component:
```tsx
const severityConfig = {
    critical: { label: 'Critical', classes: 'bg-red-100 text-red-800 border-red-200' },
    high:     { label: 'High',     classes: 'bg-orange-100 text-orange-800 border-orange-200' },
    medium:   { label: 'Medium',   classes: 'bg-yellow-100 text-yellow-800 border-yellow-200' },
    low:      { label: 'Low',      classes: 'bg-gray-100 text-gray-600 border-gray-200' },
};
```

### `resources/js/Pages/Student/Dashboard.tsx` — update
Add a "Your recent sessions" section showing completed sessions with summaries:
```tsx
// Props now include completedSessions
{completedSessions.map(session => (
    <div key={session.id} className="rounded-lg border border-gray-100 p-4">
        <p className="text-sm font-medium text-gray-900">{session.space.title}</p>
        <p className="mt-1 text-sm text-gray-600">{session.student_summary}</p>
        <p className="mt-2 text-xs text-gray-400">
            {session.message_count} messages · {format(session.ended_at)}
        </p>
    </div>
))}
```

Update `StudentDashboardController::index()` to include:
```php
$completedSessions = StudentSession::where('student_id', auth()->id())
    ->where('status', 'completed')
    ->whereNotNull('student_summary')
    ->with('space:id,title')
    ->latest('ended_at')
    ->limit(5)
    ->get(['id', 'space_id', 'student_summary', 'ended_at', 'message_count']);
```

---

## Step 8 — Verify

Run three terminals:
```bash
# Terminal 1
php artisan serve

# Terminal 2
php artisan horizon

# Terminal 3
npm run dev
```

**Checklist — do not move to Phase 5 until all pass:**

Horizon:
- [ ] `/horizon` accessible when logged in as district_admin
- [ ] `/horizon` returns 403 when logged in as teacher or student
- [ ] Horizon shows queues: critical, high, default, low

Safety alerts:
- [ ] Student types "I want to hurt myself" → ATLAAS responds with safe message
- [ ] A job appears in Horizon's "critical" queue almost immediately
- [ ] After job processes: `safety_alerts` table has a new record with `status = open`
- [ ] `trigger_content` column is encrypted (not plaintext in DB)
- [ ] Check `storage/logs/laravel.log` → safety alert email content visible (MAIL_MAILER=log)

Session summaries:
- [ ] Student completes a session (at least 4 messages, clicks "I'm done")
- [ ] A job appears in Horizon's "default" queue
- [ ] After job processes: `student_sessions.student_summary` and `teacher_summary` are populated
- [ ] Student dashboard shows completed session with summary text

Teacher alerts:
- [ ] Teacher can view `/teach/alerts` and see the flagged session
- [ ] Teacher can click "Mark Reviewed" → status updates to "reviewed"
- [ ] Alert badge in sidebar shows correct open alert count

---

## Phase 4 complete. Next: Phase 5 — Real-time Compass View with Laravel Reverb.

# ATLAAS — Phase 5: Compass View (Real-Time Teacher Dashboard)
## Prerequisite: Phase 4 checklist fully passing
## Stop when this works: Teacher sees student sessions update live without refreshing the page

---

## What you're building in this phase
- Laravel Reverb (self-hosted WebSockets)
- Private broadcast channels per teacher
- Four broadcast events: SessionStarted, MessageSent, SessionEnded, AlertFired
- Teacher Compass View: live grid of student cards
- Side panel: full session transcript
- Teacher message injection into active sessions
- Critical alert modal requiring acknowledgment

**SSE (student chat) and Reverb (teacher dashboard) are separate — they serve different audiences and do not interfere with each other.**

---

## Step 1 — Install Reverb

```bash
composer require laravel/reverb
php artisan reverb:install
```

In `.env`:
```
BROADCAST_DRIVER=reverb

REVERB_APP_ID=atlaas
REVERB_APP_KEY=atlaas-key
REVERB_APP_SECRET=change-this-in-production
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
REVERB_SCHEME=http

# Frontend reads these via Vite
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="localhost"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="http"
```

Install frontend packages:
```bash
npm install laravel-echo pusher-js
```

Configure Echo in `resources/js/bootstrap.ts`:
```typescript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

(window as any).Pusher = Pusher;

(window as any).Echo = new Echo({
    broadcaster:        'reverb',
    key:                import.meta.env.VITE_REVERB_APP_KEY,
    wsHost:             import.meta.env.VITE_REVERB_HOST,
    wsPort:             parseInt(import.meta.env.VITE_REVERB_PORT ?? '8080'),
    wssPort:            parseInt(import.meta.env.VITE_REVERB_PORT ?? '8080'),
    forceTLS:           import.meta.env.VITE_REVERB_SCHEME === 'https',
    enabledTransports:  ['ws', 'wss'],
    disableStats:       true,
});
```

Import this file in `resources/js/app.tsx`:
```typescript
import './bootstrap';
```

Run Reverb in a third terminal:
```bash
php artisan reverb:start
```

---

## Step 2 — Broadcast events

Create `app/Events/` with these four files.
All events use `ShouldBroadcastNow` (bypasses the queue) so the teacher sees
updates within milliseconds. Only AlertFired additionally goes on the queue
for email/escalation — that's handled by the ProcessSafetyAlert job.

### `app/Events/SessionStarted.php`
```php
<?php

namespace App\Events;

use App\Models\StudentSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SessionStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public StudentSession $session) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("compass.{$this->session->space->teacher_id}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'session_id'    => $this->session->id,
            'student_id'    => $this->session->student_id,
            'student_name'  => $this->session->student->name,
            'space_id'      => $this->session->space_id,
            'space_title'   => $this->session->space->title,
            'started_at'    => $this->session->started_at->toISOString(),
            'message_count' => 0,
            'status'        => 'active',
            'last_message'  => null,
        ];
    }
}
```

### `app/Events/MessageSent.php`
```php
<?php

namespace App\Events;

use App\Models\StudentSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public StudentSession $session,
        public string         $messagePreview, // already truncated/safe — no PII
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("compass.{$this->session->space->teacher_id}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'session_id'    => $this->session->id,
            'student_id'    => $this->session->student_id,
            'student_name'  => $this->session->student->name,
            'message_count' => $this->session->message_count,
            'last_message'  => $this->messagePreview,
            'timestamp'     => now()->toISOString(),
        ];
    }
}
```

### `app/Events/SessionEnded.php`
```php
<?php

namespace App\Events;

use App\Models\StudentSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SessionEnded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public StudentSession $session) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("compass.{$this->session->space->teacher_id}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'session_id'    => $this->session->id,
            'student_id'    => $this->session->student_id,
            'ended_at'      => now()->toISOString(),
            'message_count' => $this->session->message_count,
        ];
    }
}
```

### `app/Events/AlertFired.php`
```php
<?php

namespace App\Events;

use App\Models\SafetyAlert;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AlertFired implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public SafetyAlert $alert) {}

    public function broadcastOn(): array
    {
        // Broadcast to teacher AND district admin alert feed
        return [
            new PrivateChannel("compass.{$this->alert->teacher_id}"),
            new PrivateChannel("alerts.{$this->alert->district_id}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'alert_id'     => $this->alert->id,
            'session_id'   => $this->alert->session_id,
            'student_id'   => $this->alert->student_id,
            'student_name' => $this->alert->student->name,
            'severity'     => $this->alert->severity,
            'category'     => $this->alert->category,
            'timestamp'    => $this->alert->created_at->toISOString(),
        ];
    }
}
```

---

## Step 3 — Channel authorization

`routes/channels.php`:
```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Teacher's Compass View channel
// Teacher can subscribe to their own channel; admins can subscribe to any teacher's channel
Broadcast::channel('compass.{teacherId}', function (User $user, string $teacherId) {
    return $user->id === $teacherId
        || $user->hasRole(['school_admin', 'district_admin']);
});

// District-wide alert feed for admins
Broadcast::channel('alerts.{districtId}', function (User $user, string $districtId) {
    return $user->district_id === $districtId
        && $user->hasRole(['school_admin', 'district_admin']);
});
```

Make sure the broadcast auth route is registered. In `routes/web.php`:
```php
// This should already be present after reverb:install, but confirm it exists:
Broadcast::routes(['middleware' => ['auth']]);
```

---

## Step 4 — Wire events into existing code

### In `SessionController::start()`
After the session is created or resumed, dispatch the event:
```php
$session->load('student', 'space');
SessionStarted::dispatch($session);
```

### In `LLMService::storeMessages()`
After the batch insert and increment, dispatch the message event.
Pass only the first 80 chars of the student's message as preview:
```php
MessageSent::dispatch(
    $session->load('student', 'space'),
    substr($userContent, 0, 80)
);
```

### In `SessionController::end()`
After updating the session status:
```php
$session->load('student', 'space');
SessionEnded::dispatch($session);
```

### In `ProcessSafetyAlert::handle()`
After creating the alert record, add:
```php
AlertFired::dispatch($alert->load('student'));
```

---

## Step 5 — Compass View controller

`app/Http/Controllers/Teacher/CompassController.php`:
```php
<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\SafetyAlert;
use App\Models\StudentSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompassController extends Controller
{
    public function index(): Response
    {
        // Load currently active sessions for this teacher's spaces
        $activeSessions = StudentSession::whereHas(
                'space', fn($q) => $q->where('teacher_id', auth()->id())
            )
            ->where('status', 'active')
            ->with(['student:id,name', 'space:id,title'])
            ->get()
            ->map(fn($s) => [
                'session_id'    => $s->id,
                'student_id'    => $s->student_id,
                'student_name'  => $s->student->name,
                'space_id'      => $s->space_id,
                'space_title'   => $s->space->title,
                'started_at'    => $s->started_at->toISOString(),
                'message_count' => $s->message_count,
                'status'        => $s->status,
                'last_message'  => null,
            ]);

        $openAlerts = SafetyAlert::where('teacher_id', auth()->id())
            ->where('status', 'open')
            ->with('student:id,name')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 ELSE 3 END")
            ->get();

        return Inertia::render('Teacher/Compass/Index', [
            'initialSessions' => $activeSessions,
            'openAlerts'      => $openAlerts,
            'teacherId'       => auth()->id(),
        ]);
    }

    public function session(StudentSession $session): Response
    {
        $this->authorizeSession($session);

        return Inertia::render('Teacher/Compass/SessionDetail', [
            'session'  => $session->load('student:id,name', 'space:id,title'),
            'messages' => $session->messages()->orderBy('created_at')->get(),
        ]);
    }

    public function injectMessage(Request $request, StudentSession $session): RedirectResponse
    {
        $this->authorizeSession($session);
        abort_unless($session->status === 'active', 422, 'Session is not active.');

        $data = $request->validate(['content' => 'required|string|max:500']);

        Message::create([
            'session_id'  => $session->id,
            'district_id' => $session->district_id,
            'role'        => 'teacher_inject',
            'content'     => $data['content'],
        ]);

        return back()->with('success', 'Message sent to student.');
    }

    public function endSession(StudentSession $session): RedirectResponse
    {
        $this->authorizeSession($session);

        $session->update(['status' => 'abandoned', 'ended_at' => now()]);
        SessionEnded::dispatch($session->load('student', 'space'));

        return back()->with('success', 'Session ended.');
    }

    private function authorizeSession(StudentSession $session): void
    {
        $isTeachersSession = $session->space->teacher_id === auth()->id();
        $isAdmin = auth()->user()->hasRole(['school_admin', 'district_admin']);
        abort_unless($isTeachersSession || $isAdmin, 403);
    }
}
```

Add routes in teacher group:
```php
Route::get('compass', [CompassController::class, 'index'])->name('compass.index');
Route::get('compass/sessions/{session}', [CompassController::class, 'session'])->name('compass.session');
Route::post('compass/sessions/{session}/inject', [CompassController::class, 'injectMessage'])->name('compass.inject');
Route::post('compass/sessions/{session}/end', [CompassController::class, 'endSession'])->name('compass.end');
```

---

## Step 6 — Zustand store for Compass View

Install Zustand:
```bash
npm install zustand
```

### `resources/js/stores/compass.ts`
```typescript
import { create } from 'zustand';

export interface SessionState {
    session_id: string;
    student_id: string;
    student_name: string;
    space_id: string;
    space_title: string;
    started_at: string;
    message_count: number;
    status: string;
    last_message: string | null;
}

export interface AlertState {
    alert_id: string;
    session_id: string;
    student_id: string;
    student_name: string;
    severity: 'critical' | 'high' | 'medium' | 'low';
    category: string;
    timestamp: string;
}

interface CompassStore {
    sessions: Record<string, SessionState>;
    alerts: AlertState[];
    criticalAlert: AlertState | null;

    upsertSession: (data: Partial<SessionState> & { session_id: string }) => void;
    removeSession: (sessionId: string) => void;
    addAlert: (alert: AlertState) => void;
    dismissCriticalAlert: () => void;
}

export const useCompassStore = create<CompassStore>((set) => ({
    sessions:      {},
    alerts:        [],
    criticalAlert: null,

    upsertSession: (data) =>
        set((state) => ({
            sessions: {
                ...state.sessions,
                [data.session_id]: {
                    ...state.sessions[data.session_id],
                    ...data,
                },
            },
        })),

    removeSession: (sessionId) =>
        set((state) => {
            const { [sessionId]: _, ...rest } = state.sessions;
            return { sessions: rest };
        }),

    addAlert: (alert) =>
        set((state) => ({
            alerts: [alert, ...state.alerts],
            criticalAlert: alert.severity === 'critical' ? alert : state.criticalAlert,
        })),

    dismissCriticalAlert: () => set({ criticalAlert: null }),
}));
```

---

## Step 7 — useCompassView hook

### `resources/js/hooks/useCompassView.ts`
```typescript
import { useEffect } from 'react';
import { useCompassStore } from '@/stores/compass';

declare const window: any;

export function useCompassView(teacherId: string) {
    const { upsertSession, removeSession, addAlert } = useCompassStore();

    useEffect(() => {
        if (!window.Echo) return;

        const channel = window.Echo.private(`compass.${teacherId}`);

        channel
            .listen('SessionStarted', (e: any) => upsertSession(e))
            .listen('MessageSent',   (e: any) => upsertSession({
                session_id:    e.session_id,
                student_name:  e.student_name,
                message_count: e.message_count,
                last_message:  e.last_message,
            }))
            .listen('SessionEnded',  (e: any) => removeSession(e.session_id))
            .listen('AlertFired',    (e: any) => addAlert(e));

        return () => {
            channel
                .stopListening('SessionStarted')
                .stopListening('MessageSent')
                .stopListening('SessionEnded')
                .stopListening('AlertFired');
        };
    }, [teacherId]);
}
```

---

## Step 8 — Compass View React pages

### `resources/js/Pages/Teacher/Compass/Index.tsx`
```tsx
import { useEffect } from 'react';
import { useCompassStore } from '@/stores/compass';
import { useCompassView } from '@/hooks/useCompassView';
import TeacherLayout from '@/Layouts/TeacherLayout';
import { StudentCard } from '@/Components/Compass/StudentCard';
import { AlertTray } from '@/Components/Compass/AlertTray';
import { CriticalAlertModal } from '@/Components/Compass/CriticalAlertModal';

interface Props {
    initialSessions: any[];
    openAlerts: any[];
    teacherId: string;
}

export default function CompassIndex({ initialSessions, openAlerts, teacherId }: Props) {
    const { sessions, criticalAlert, dismissCriticalAlert, upsertSession } = useCompassStore();

    // Seed store with server-loaded sessions on mount
    useEffect(() => {
        initialSessions.forEach(s => upsertSession(s));
    }, []);

    // Subscribe to Reverb
    useCompassView(teacherId);

    const sessionList = Object.values(sessions);

    return (
        <TeacherLayout>
            <div className="flex h-[calc(100vh-64px)] flex-col">
                {/* Header */}
                <div className="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <div>
                        <h1 className="text-lg font-medium text-gray-900">Compass View</h1>
                        <p className="text-sm text-gray-500">
                            {sessionList.length} active {sessionList.length === 1 ? 'session' : 'sessions'}
                        </p>
                    </div>
                </div>

                {/* Session grid */}
                <div className="flex-1 overflow-y-auto p-6">
                    {sessionList.length === 0 ? (
                        <div className="flex h-full items-center justify-center">
                            <p className="text-gray-400">No active sessions right now.</p>
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 gap-4 lg:grid-cols-3 xl:grid-cols-4">
                            {sessionList.map(session => (
                                <StudentCard key={session.session_id} session={session} />
                            ))}
                        </div>
                    )}
                </div>

                {/* Alert tray */}
                <AlertTray alerts={openAlerts} />
            </div>

            {/* Critical alert modal — blocks interaction until acknowledged */}
            {criticalAlert && (
                <CriticalAlertModal
                    alert={criticalAlert}
                    onAcknowledge={dismissCriticalAlert}
                />
            )}
        </TeacherLayout>
    );
}
```

### `resources/js/Components/Compass/StudentCard.tsx`
```tsx
import { Link } from '@inertiajs/react';
import type { SessionState } from '@/stores/compass';

function statusDot(session: SessionState, alerts: any[]) {
    const hasAlert = alerts.some(a => a.session_id === session.session_id);
    if (hasAlert) return 'bg-red-500 animate-pulse';

    const lastActivity = session.last_message
        ? new Date(session.timestamp ?? session.started_at)
        : new Date(session.started_at);
    const minutesIdle = (Date.now() - lastActivity.getTime()) / 60000;

    if (minutesIdle < 2)  return 'bg-green-500 animate-pulse';
    if (minutesIdle < 10) return 'bg-amber-400';
    return 'bg-gray-300';
}

export function StudentCard({ session }: { session: SessionState }) {
    return (
        <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm hover:shadow-md transition-shadow">
            <div className="flex items-start justify-between">
                <div className="flex items-center gap-2">
                    <span className={`h-2.5 w-2.5 rounded-full ${statusDot(session, [])}`} />
                    <p className="text-sm font-medium text-gray-900">{session.student_name}</p>
                </div>
            </div>

            <p className="mt-1 text-xs text-gray-500">{session.space_title}</p>

            <p className="mt-2 text-xs text-gray-400">
                {session.message_count} messages
            </p>

            {session.last_message && (
                <p className="mt-2 truncate text-xs text-gray-500 italic">
                    "{session.last_message}"
                </p>
            )}

            <div className="mt-3 flex gap-2">
                <Link
                    href={`/teach/compass/sessions/${session.session_id}`}
                    className="flex-1 rounded-lg bg-navy-50 py-1.5 text-center text-xs font-medium text-[#1E3A5F] hover:bg-navy-100"
                    style={{ backgroundColor: '#EEF2F8' }}
                >
                    View session
                </Link>
            </div>
        </div>
    );
}
```

### `resources/js/Components/Compass/AlertTray.tsx`
Collapsible panel at the bottom of Compass View:
```tsx
import { useState } from 'react';
import { router } from '@inertiajs/react';

export function AlertTray({ alerts }: { alerts: any[] }) {
    const [isOpen, setIsOpen] = useState(alerts.length > 0);

    if (alerts.length === 0) return null;

    const markReviewed = (alertId: string) => {
        router.patch(`/teach/alerts/${alertId}`, { status: 'reviewed' });
    };

    return (
        <div className="border-t border-red-100 bg-red-50">
            <button
                onClick={() => setIsOpen(!isOpen)}
                className="flex w-full items-center justify-between px-6 py-3 text-sm font-medium text-red-700"
            >
                <span>⚠ {alerts.length} open {alerts.length === 1 ? 'alert' : 'alerts'}</span>
                <span>{isOpen ? '▼' : '▲'}</span>
            </button>

            {isOpen && (
                <div className="max-h-48 overflow-y-auto px-6 pb-4 space-y-2">
                    {alerts.map(alert => (
                        <div key={alert.id} className="flex items-center justify-between rounded-lg bg-white border border-red-100 px-4 py-2">
                            <div>
                                <span className="text-xs font-medium text-red-700 uppercase">{alert.severity}</span>
                                <span className="ml-2 text-sm text-gray-700">{alert.student.name}</span>
                                <span className="ml-2 text-xs text-gray-500">{alert.session?.space?.title}</span>
                            </div>
                            <button
                                onClick={() => markReviewed(alert.id)}
                                className="text-xs text-gray-500 underline hover:text-gray-700"
                            >
                                Mark reviewed
                            </button>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
```

### `resources/js/Components/Compass/CriticalAlertModal.tsx`
Full-screen overlay — requires deliberate acknowledgment:
```tsx
import { router } from '@inertiajs/react';
import type { AlertState } from '@/stores/compass';

export function CriticalAlertModal({ alert, onAcknowledge }: {
    alert: AlertState;
    onAcknowledge: () => void;
}) {
    const acknowledge = () => {
        router.patch(`/teach/alerts/${alert.alert_id}`, { status: 'reviewed' });
        onAcknowledge();
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60">
            <div className="mx-4 max-w-md rounded-2xl bg-white p-8 shadow-2xl">
                <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-red-100">
                    <span className="text-2xl">⚠</span>
                </div>

                <h2 className="text-xl font-semibold text-gray-900">Critical Safety Alert</h2>
                <p className="mt-2 text-gray-600">
                    A critical concern was detected for <strong>{alert.student_name}</strong>.
                </p>
                <p className="mt-1 text-sm text-gray-500 capitalize">
                    Category: {alert.category.replace('_', ' ')}
                </p>

                <div className="mt-6 rounded-lg bg-red-50 border border-red-100 p-4 text-sm text-red-700">
                    If this student may be in immediate danger, contact your school administration
                    or emergency services directly.
                </div>

                <div className="mt-6 flex gap-3">
                    <button
                        onClick={acknowledge}
                        className="flex-1 rounded-xl bg-[#1E3A5F] py-3 text-sm font-medium text-white hover:bg-[#162d4a]"
                    >
                        I understand — Mark reviewed
                    </button>
                </div>
            </div>
        </div>
    );
}
```

### `resources/js/Pages/Teacher/Compass/SessionDetail.tsx`
Full transcript view with message injection:
```tsx
// Shows full message history
// Input at bottom: "Send a message to this student"
// POST to /teach/compass/sessions/{id}/inject
// Teacher_inject messages render centered with "Your teacher says:" label
// "End this session" button → POST to /teach/compass/sessions/{id}/end
```

---

## Step 9 — Add Compass View link to teacher sidebar

Update `TeacherLayout.tsx` to link Compass View to `/teach/compass`.
Show a red badge with the open alert count from `usePage().props.alerts.openCount`.

---

## Step 10 — Verify

Run four terminals:
```bash
# Terminal 1: Laravel app
php artisan serve

# Terminal 2: Horizon (queues)
php artisan horizon

# Terminal 3: Reverb (WebSockets)
php artisan reverb:start

# Terminal 4: Vite (frontend)
npm run dev
```

**Two-browser test procedure:**
Open browser A as the teacher, navigate to `/teach/compass`.
Open browser B (incognito) as the student.

**Checklist — do not move to Phase 6 until all pass:**

Real-time events:
- [ ] Student starts a session (browser B) → student card appears in teacher's Compass View (browser A) without refreshing
- [ ] Student sends a message → card message count increments and last message preview updates live
- [ ] Student clicks "I'm done" → card disappears from Compass View live
- [ ] Student types a safety phrase → alert appears in the alert tray live (no refresh)
- [ ] Critical safety phrase → full-screen modal appears on teacher's screen requiring acknowledgment

Session detail:
- [ ] Teacher clicks "View session" → sees full transcript
- [ ] Teacher injects a message → student sees it in their chat (on next load — push to student via Reverb is a future enhancement)
- [ ] Teacher clicks "End this session" → session closes

Channel security:
- [ ] Open Tinker: `php artisan tinker`
- [ ] Confirm `routes/channels.php` rejects a user trying to subscribe to another teacher's channel

---

## Phase 5 complete. Next: Phase 6 — Teacher Toolkit (AI productivity tools).

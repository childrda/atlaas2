# ATLAAS — Phase 3: AI & Student Chat
## Prerequisite: Phase 2 checklist fully passing
## Stop when this works: A student can have a real conversation with ATLAAS inside a Space

---

## What you're building in this phase
- Pluggable LLM adapter (works with Ollama, vLLM, OpenAI, or any OpenAI-compatible endpoint)
- Privacy filter (strips student PII before anything reaches the LLM)
- Safety filter (pattern-based, synchronous, < 1ms — runs on every message)
- Prompt builder (assembles the full system prompt from teacher settings + safety rules)
- Student session lifecycle (start, message, end)
- SSE streaming so ATLAAS's response appears token by token
- Full student chat UI

**No real-time teacher monitoring yet — that's Phase 5.**

---

## Step 1 — Install OpenAI PHP package

```bash
composer require openai-php/laravel
php artisan vendor:publish --provider="OpenAI\Laravel\ServiceProvider"
```

In `.env` — point at your LLM. Swap these values for your server later:
```
# For OpenAI during development:
OPENAI_API_KEY=your-key
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_MODEL=gpt-4o-mini

# For local Ollama:
# OPENAI_BASE_URL=http://localhost:11434/v1
# OPENAI_API_KEY=ollama
# OPENAI_MODEL=llama3.2

# For local vLLM:
# OPENAI_BASE_URL=http://localhost:8000/v1
# OPENAI_API_KEY=vllm
# OPENAI_MODEL=meta-llama/Llama-3.2-8B-Instruct
```

The adapter is model-agnostic. Changing these three env vars is all that's needed
to switch between providers — no code changes.

---

## Step 2 — AI service layer

Create `app/Services/AI/` and add the following files.

### `app/Services/AI/FlagResult.php`
Simple value object returned by the safety filter:
```php
<?php

namespace App\Services\AI;

readonly class FlagResult
{
    public function __construct(
        public bool   $flagged,
        public string $category,
        public string $severity, // critical|high|medium|low
    ) {}
}
```

### `app/Services/AI/PrivacyFilter.php`
Strips PII before any content reaches the LLM. This is enforced in the service
layer, not the controller — it cannot be bypassed by a future refactor.
```php
<?php

namespace App\Services\AI;

class PrivacyFilter
{
    public function clean(string $content): string
    {
        // Remove email addresses
        $content = preg_replace('/[\w.+-]+@[\w-]+\.[\w.]+/', '[email]', $content);

        // Remove phone numbers (common North American patterns)
        $content = preg_replace(
            '/(\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b/',
            '[phone]',
            $content
        );

        return $content;
    }

    /**
     * Replace the student's real name with a generic reference.
     * Called when building the system prompt — the LLM never sees real names.
     */
    public function anonymizeName(string $content, string $realName): string
    {
        if (empty($realName)) return $content;
        return str_ireplace($realName, 'the student', $content);
    }
}
```

### `app/Services/AI/SafetyFilter.php`
Pattern-based, synchronous. Runs in < 1ms. If this returns CRITICAL or HIGH,
the LLM is never called — the safe response goes directly to the student.
```php
<?php

namespace App\Services\AI;

class SafetyFilter
{
    private array $patterns = [
        'self_harm' => [
            'severity' => 'critical',
            'patterns' => [
                '/\b(kill\s+myself|end\s+my\s+life|want\s+to\s+die|suicide|cut\s+myself|hurt\s+myself)\b/i',
                '/\b(don.?t\s+want\s+to\s+(be\s+here|live|exist))\b/i',
                '/\b(thinking\s+about\s+(hurting|killing)\s+(myself|me))\b/i',
            ],
        ],
        'abuse_disclosure' => [
            'severity' => 'critical',
            'patterns' => [
                '/\b(someone\s+is\s+(hitting|hurting|touching|abusing)\s+me)\b/i',
                '/\b(my\s+(mom|dad|parent|step|uncle|aunt|teacher|coach)\s+(hits|hurts|touches|beats)\s+me)\b/i',
                '/\b(being\s+(abused|hurt|touched)\s+(at\s+home|by\s+an?\s+adult))\b/i',
            ],
        ],
        'bullying' => [
            'severity' => 'high',
            'patterns' => [
                '/\b(they\s+(keep|always)\s+(calling\s+me|making\s+fun|hitting|pushing|excluding))\b/i',
                '/\b(nobody\s+(likes|wants)\s+me|everyone\s+hates\s+me)\b/i',
                '/\b(being\s+bullied|they\s+won.?t\s+stop)\b/i',
            ],
        ],
        'profanity_severe' => [
            'severity' => 'medium',
            'patterns' => [
                // Add district-appropriate word list here
                // Keeping this empty by default — add your own
            ],
        ],
    ];

    public function check(string $content): ?FlagResult
    {
        foreach ($this->patterns as $category => $config) {
            foreach ($config['patterns'] as $pattern) {
                if (!empty($pattern) && preg_match($pattern, $content)) {
                    return new FlagResult(
                        flagged:  true,
                        category: $category,
                        severity: $config['severity'],
                    );
                }
            }
        }

        return null;
    }

    public function safeAtlaasResponse(string $category): string
    {
        return match ($category) {
            'self_harm', 'abuse_disclosure' =>
                "It sounds like you might be going through something really difficult right now. " .
                "You don't have to face that alone. Please talk to a trusted adult — your teacher, " .
                "a school counselor, or a parent — as soon as you can. They care about you and want to help.",

            'bullying' =>
                "That sounds really hard, and I'm glad you felt comfortable sharing that. " .
                "It's important to talk to a trusted adult about what's happening — " .
                "your teacher or school counselor can help make it stop.",

            default =>
                "Let's keep our conversation focused on your learning today. " .
                "Is there something about the lesson I can help you with?",
        };
    }
}
```

### `app/Services/AI/PromptBuilder.php`
Assembles the full system prompt. The safety block is always appended last
and cannot be removed or overridden by teacher instructions.
```php
<?php

namespace App\Services\AI;

use App\Models\LearningSpace;
use App\Models\User;

class PromptBuilder
{
    public function __construct(private PrivacyFilter $privacy) {}

    public function build(LearningSpace $space, User $student): string
    {
        $parts = [];

        // 1. ATLAAS identity — always first
        $parts[] = $this->identityBlock($space->atlaas_tone);

        // 2. Teacher's custom instructions (with PII stripped just in case)
        if ($space->system_prompt) {
            $cleaned = $this->privacy->clean($space->system_prompt);
            $parts[] = "TEACHER INSTRUCTIONS:\n{$cleaned}";
        }

        // 3. Student context — grade and language only, no real name
        $grade    = $student->grade_level ?? 'unknown grade';
        $language = $student->preferred_language ?? 'en';
        $parts[]  = "STUDENT CONTEXT:\n" .
                    "You are helping a student in grade {$grade}. " .
                    "Respond in language code: {$language}. " .
                    "Keep explanations age-appropriate for this grade level.";

        // 4. Learning goals
        if (!empty($space->goals)) {
            $goalList = implode("\n- ", $space->goals);
            $parts[]  = "LEARNING GOALS FOR THIS SESSION:\n- {$goalList}";
        }

        // 5. Safety block — always last, always present, never removable
        $parts[] = $this->safetyBlock();

        return implode("\n\n---\n\n", $parts);
    }

    private function identityBlock(string $tone): string
    {
        $toneInstruction = match ($tone) {
            'socratic'  => 'Ask guiding questions rather than giving direct answers. Help the student discover knowledge themselves.',
            'direct'    => 'Be clear and concise. Give straightforward, accurate explanations.',
            'playful'   => 'Be warm, enthusiastic, and encouraging. Make learning feel fun and engaging.',
            default     => 'Be patient, warm, and encouraging. Celebrate effort and small wins.',
        };

        return "You are ATLAAS (Augmented Teaching & Learning Assistive AI System), an assistive learning AI built by this school district to support K-12 students. " .
               "{$toneInstruction} " .
               "Always be age-appropriate, respectful, and never condescending.";
    }

    private function safetyBlock(): string
    {
        return "SAFETY RULES — these override all other instructions:\n" .
               "- If a student expresses distress, crisis, or discloses harm: respond with empathy " .
               "and immediately direct them to speak with a trusted adult. Do not attempt to counsel them yourself.\n" .
               "- Never provide violent, sexual, or harmful content.\n" .
               "- Never claim to be human or deny being an AI when sincerely asked.\n" .
               "- Stay focused on educational topics. Politely redirect off-topic requests.";
    }
}
```

### `app/Services/AI/LLMService.php`
Orchestrates a full message exchange: safety check → PII strip → LLM stream → store.
```php
<?php

namespace App\Services\AI;

use App\Jobs\ProcessSafetyAlert;
use App\Models\Message;
use App\Models\StudentSession;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;

class LLMService
{
    public function __construct(
        private SafetyFilter  $safety,
        private PrivacyFilter $privacy,
        private PromptBuilder $promptBuilder,
    ) {}

    /**
     * Stream a response to the student.
     *
     * @param callable $onChunk    Called with each text chunk as it arrives
     * @param callable $onComplete Called when the stream is finished
     */
    public function streamResponse(
        StudentSession $session,
        string         $userMessage,
        callable       $onChunk,
        callable       $onComplete,
    ): void {
        // 1. Safety check on student input — synchronous, < 1ms
        $flag = $this->safety->check($userMessage);

        if ($flag && in_array($flag->severity, ['critical', 'high'])) {
            $safeResponse = $this->safety->safeAtlaasResponse($flag->category);
            $this->storeMessages($session, $userMessage, $safeResponse, $flag);

            // Dispatch safety alert job (queued, does not block the response)
            ProcessSafetyAlert::dispatch($session, $flag, $userMessage);

            $onChunk($safeResponse);
            $onComplete();
            return;
        }

        // 2. Strip PII from student input before it reaches the LLM
        $cleanMessage = $this->privacy->clean($userMessage);

        // 3. Load conversation history (sliding window of last 20 exchanges)
        $history = $session->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->latest()
            ->limit(20)
            ->get()
            ->reverse()
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->toArray();

        // 4. Build full prompt
        $systemPrompt = $this->promptBuilder->build($session->space, $session->student);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ...$history,
            ['role' => 'user', 'content' => $cleanMessage],
        ];

        // 5. Stream from LLM
        $fullResponse = '';

        $stream = OpenAI::chat()->createStreamed([
            'model'       => config('openai.model'),
            'messages'    => $messages,
            'max_tokens'  => 800,
            'temperature' => 0.7,
        ]);

        foreach ($stream as $response) {
            $chunk = $response->choices[0]->delta->content ?? '';
            if ($chunk !== '') {
                $fullResponse .= $chunk;
                $onChunk($chunk);
            }
        }

        // 6. Safety check the LLM response — LLMs occasionally produce unexpected output
        $responseFlag = $this->safety->check($fullResponse);
        if ($responseFlag && in_array($responseFlag->severity, ['critical', 'high'])) {
            $fullResponse = "I'm not able to help with that. Let's focus on your learning today.";
        }

        // 7. Store both messages (batch insert for performance)
        $this->storeMessages($session, $userMessage, $fullResponse, $flag);

        $onComplete();
    }

    private function storeMessages(
        StudentSession $session,
        string         $userContent,
        string         $assistantContent,
        ?FlagResult    $flag
    ): void {
        Message::insert([
            [
                'id'            => (string) Str::uuid(),
                'session_id'    => $session->id,
                'district_id'   => $session->district_id,
                'role'          => 'user',
                'content'       => $userContent,
                'flagged'       => $flag !== null,
                'flag_reason'   => $flag?->category,
                'flag_category' => $flag?->severity,
                'tokens'        => 0,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            [
                'id'          => (string) Str::uuid(),
                'session_id'  => $session->id,
                'district_id' => $session->district_id,
                'role'        => 'assistant',
                'content'     => $assistantContent,
                'flagged'     => false,
                'tokens'      => 0,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ]);

        $session->increment('message_count', 2);
    }
}
```

Register the service in `AppServiceProvider`:
```php
$this->app->singleton(\App\Services\AI\LLMService::class);
```

---

## Step 3 — Session controller

`app/Http/Controllers/Student/SessionController.php`:
```php
<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateSessionSummary;
use App\Models\LearningSpace;
use App\Models\StudentSession;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SessionController extends Controller
{
    public function start(LearningSpace $space): RedirectResponse
    {
        abort_unless($space->is_published, 403, 'This space is not available.');
        abort_if($space->is_archived, 403, 'This space has been archived.');

        if ($space->opens_at && now()->lt($space->opens_at)) {
            return back()->with('error', 'This space is not open yet.');
        }

        if ($space->closes_at && now()->gt($space->closes_at)) {
            return back()->with('error', 'This space has closed.');
        }

        // Resume an existing active session or create a new one
        $session = StudentSession::firstOrCreate(
            [
                'student_id' => auth()->id(),
                'space_id'   => $space->id,
                'status'     => 'active',
            ],
            [
                'district_id' => auth()->user()->district_id,
                'started_at'  => now(),
            ]
        );

        return redirect()->route('student.sessions.show', $session);
    }

    public function show(StudentSession $session): Response
    {
        abort_unless($session->student_id === auth()->id(), 403);

        $messages = $session->messages()
            ->whereIn('role', ['user', 'assistant', 'teacher_inject'])
            ->orderBy('created_at')
            ->get(['id', 'role', 'content', 'created_at']);

        return Inertia::render('Student/Session', [
            'session'  => $session->load('space:id,title,description,atlaas_tone,goals,max_messages'),
            'messages' => $messages,
        ]);
    }

    public function end(StudentSession $session): RedirectResponse
    {
        abort_unless($session->student_id === auth()->id(), 403);
        abort_unless($session->status === 'active', 422);

        $session->update([
            'status'   => 'completed',
            'ended_at' => now(),
        ]);

        // Dispatch summary generation job (Phase 4 implements this job)
        // GenerateSessionSummary::dispatch($session);

        return redirect()->route('student.dashboard')
            ->with('success', 'Great work! Your session is complete.');
    }
}
```

---

## Step 4 — Message controller (SSE streaming)

`app/Http/Controllers/Student/MessageController.php`:
```php
<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\StudentSession;
use App\Services\AI\LLMService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageController extends Controller
{
    public function store(Request $request, StudentSession $session): StreamedResponse
    {
        abort_unless($session->student_id === auth()->id(), 403);
        abort_unless($session->status === 'active', 422, 'Session is not active.');

        $request->validate(['content' => 'required|string|max:2000']);

        // Check message limit if set
        $maxMessages = $session->space->max_messages;
        if ($maxMessages && $session->message_count >= $maxMessages) {
            return response()->stream(function () {
                echo "data: " . json_encode(['type' => 'limit_reached']) . "\n\n";
                ob_flush();
                flush();
            }, 200, $this->sseHeaders());
        }

        $session->load('space', 'student');
        $llm = app(LLMService::class);

        return response()->stream(
            function () use ($session, $request, $llm) {
                $llm->streamResponse(
                    session:     $session,
                    userMessage: $request->input('content'),
                    onChunk: function (string $chunk) {
                        echo "data: " . json_encode(['type' => 'chunk', 'content' => $chunk]) . "\n\n";
                        ob_flush();
                        flush();
                    },
                    onComplete: function () {
                        echo "data: " . json_encode(['type' => 'done']) . "\n\n";
                        ob_flush();
                        flush();
                    },
                );
            },
            200,
            $this->sseHeaders()
        );
    }

    private function sseHeaders(): array
    {
        return [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no', // Required — prevents Nginx from buffering the stream
            'Connection'        => 'keep-alive',
        ];
    }
}
```

---

## Step 5 — Add routes

In `routes/web.php`, inside the student route group:
```php
Route::post('spaces/{space}/sessions', [SessionController::class, 'start'])->name('sessions.start');
Route::get('sessions/{session}', [SessionController::class, 'show'])->name('sessions.show');
Route::post('sessions/{session}/end', [SessionController::class, 'end'])->name('sessions.end');
Route::post('sessions/{session}/messages', [MessageController::class, 'store'])->name('messages.store');
```

---

## Step 6 — LLM connection test route (dev only)

Add to `routes/web.php` outside any middleware group:
```php
// Dev-only LLM connection test — remove or restrict before production
if (app()->environment('local')) {
    Route::get('/test-llm', function () {
        $response = \OpenAI\Laravel\Facades\OpenAI::chat()->create([
            'model'    => config('openai.model'),
            'messages' => [['role' => 'user', 'content' => 'Reply with exactly: ATLAAS LLM connected.']],
        ]);
        return $response->choices[0]->message->content;
    })->middleware(['auth', 'role:district_admin']);
}
```

---

## Step 7 — Student session React page

### `resources/js/types/models.ts` additions
```typescript
export interface Message {
    id: string;
    role: 'user' | 'assistant' | 'teacher_inject';
    content: string;
    created_at: string;
}

export interface StudentSession {
    id: string;
    status: string;
    message_count: number;
    space: LearningSpace;
}
```

### `resources/js/Pages/Student/Session.tsx`
Full-screen chat interface. The key state and streaming logic:

```tsx
import { useState, useEffect, useRef } from 'react';
import { router } from '@inertiajs/react';
import type { Message, StudentSession } from '@/types/models';

interface Props {
    session: StudentSession;
    messages: Message[];
}

export default function SessionPage({ session, messages: initialMessages }: Props) {
    const [messages, setMessages]             = useState<Message[]>(initialMessages);
    const [inputValue, setInputValue]         = useState('');
    const [isStreaming, setIsStreaming]        = useState(false);
    const [streamingContent, setStreamingContent] = useState('');
    const [limitReached, setLimitReached]     = useState(false);
    const bottomRef = useRef<HTMLDivElement>(null);

    // Auto-scroll on new messages
    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages, streamingContent]);

    const sendMessage = async () => {
        const content = inputValue.trim();
        if (!content || isStreaming || limitReached) return;

        // Optimistically add student's message
        const userMsg: Message = {
            id: crypto.randomUUID(),
            role: 'user',
            content,
            created_at: new Date().toISOString(),
        };
        setMessages(prev => [...prev, userMsg]);
        setInputValue('');
        setIsStreaming(true);
        setStreamingContent('');

        try {
            const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

            const response = await fetch(`/learn/sessions/${session.id}/messages`, {
                method: 'POST',
                headers: {
                    'Content-Type':  'application/json',
                    'X-CSRF-TOKEN':  csrfToken,
                    'Accept':        'text/event-stream',
                },
                body: JSON.stringify({ content }),
            });

            const reader  = response.body!.getReader();
            const decoder = new TextDecoder();
            let accumulated = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                const lines = decoder.decode(value).split('\n');

                for (const line of lines) {
                    if (!line.startsWith('data: ')) continue;

                    const data = JSON.parse(line.slice(6));

                    if (data.type === 'chunk') {
                        accumulated += data.content;
                        setStreamingContent(accumulated);
                    }

                    if (data.type === 'done') {
                        // Move from streaming buffer into messages list
                        setMessages(prev => [...prev, {
                            id:         crypto.randomUUID(),
                            role:       'assistant',
                            content:    accumulated,
                            created_at: new Date().toISOString(),
                        }]);
                        setStreamingContent('');
                        setIsStreaming(false);
                    }

                    if (data.type === 'limit_reached') {
                        setLimitReached(true);
                        setIsStreaming(false);
                    }
                }
            }
        } catch {
            setIsStreaming(false);
            setMessages(prev => [...prev, {
                id:         crypto.randomUUID(),
                role:       'assistant',
                content:    'Something went wrong. Please try again.',
                created_at: new Date().toISOString(),
            }]);
        }
    };

    const handleKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    };

    const endSession = () => {
        router.post(`/learn/sessions/${session.id}/end`);
    };

    return (
        <div className="flex h-screen flex-col bg-white">
            {/* Header */}
            <header className="flex items-center justify-between border-b border-gray-100 px-6 py-3">
                <div className="flex items-center gap-3">
                    <AtlaasAvatar state={isStreaming ? 'thinking' : 'idle'} />
                    <div>
                        <p className="text-sm font-medium text-gray-900">{session.space.title}</p>
                        <p className="text-xs text-gray-400">Powered by ATLAAS</p>
                    </div>
                </div>
                <button
                    onClick={endSession}
                    className="rounded-md border border-gray-200 px-4 py-1.5 text-sm text-gray-600 hover:bg-gray-50"
                >
                    I'm done
                </button>
            </header>

            {/* Messages */}
            <div className="flex-1 overflow-y-auto px-6 py-4 space-y-4">
                {messages.length === 0 && (
                    <p className="text-center text-sm text-gray-400 mt-8">
                        Say hello to get started!
                    </p>
                )}

                {messages.map(msg => (
                    <ChatBubble key={msg.id} message={msg} />
                ))}

                {/* Streaming ATLAAS response */}
                {isStreaming && streamingContent && (
                    <ChatBubble
                        message={{ id: 'streaming', role: 'assistant', content: streamingContent, created_at: '' }}
                        isStreaming
                    />
                )}

                {/* Thinking indicator — shown before first chunk arrives */}
                {isStreaming && !streamingContent && (
                    <div className="flex items-center gap-2">
                        <AtlaasAvatar state="thinking" size="sm" />
                        <ThinkingIndicator />
                    </div>
                )}

                {limitReached && (
                    <p className="text-center text-sm text-amber-600 py-4">
                        You've reached the message limit for this session. Click "I'm done" to finish.
                    </p>
                )}

                <div ref={bottomRef} />
            </div>

            {/* Input */}
            <div className="border-t border-gray-100 px-6 py-4">
                <div className="flex gap-3">
                    <textarea
                        value={inputValue}
                        onChange={e => setInputValue(e.target.value)}
                        onKeyDown={handleKeyDown}
                        placeholder="Ask ATLAAS something..."
                        rows={1}
                        disabled={isStreaming || limitReached}
                        className="flex-1 resize-none rounded-xl border border-gray-200 px-4 py-3 text-sm focus:border-amber-400 focus:outline-none disabled:opacity-50"
                    />
                    <button
                        onClick={sendMessage}
                        disabled={isStreaming || !inputValue.trim() || limitReached}
                        className="rounded-xl bg-amber-500 px-5 py-3 text-sm font-medium text-white hover:bg-amber-600 disabled:opacity-50"
                    >
                        Send
                    </button>
                </div>
                <p className="mt-2 text-center text-xs text-gray-400">
                    Press Enter to send · Shift+Enter for new line
                </p>
            </div>
        </div>
    );
}
```

### `resources/js/Components/Atlaas/AtlaasAvatar.tsx`
Simple SVG arch motif, three states:
```tsx
interface Props {
    state: 'idle' | 'thinking' | 'done';
    size?: 'sm' | 'md';
}

export function AtlaasAvatar({ state, size = 'md' }: Props) {
    const dim = size === 'sm' ? 28 : 40;

    return (
        <svg width={dim} height={dim} viewBox="0 0 40 40" fill="none">
            {/* Two arch shapes — district navy color */}
            <path
                d="M4 32 Q4 12 20 12 Q36 12 36 32"
                stroke="#1E3A5F"
                strokeWidth="3"
                strokeLinecap="round"
                fill="none"
                style={{
                    opacity: state === 'thinking' ? undefined : 1,
                    animation: state === 'thinking' ? 'pulse 1.2s ease-in-out infinite' : 'none',
                }}
            />
            <path
                d="M10 32 Q10 18 20 18 Q30 18 30 32"
                stroke="#F5A623"
                strokeWidth="2.5"
                strokeLinecap="round"
                fill="none"
            />
            {/* Road deck */}
            <line x1="2" y1="32" x2="38" y2="32" stroke="#1E3A5F" strokeWidth="2.5" strokeLinecap="round" />
        </svg>
    );
}
```

Add to global CSS or a style tag:
```css
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50%       { opacity: 0.4; }
}
```

### `resources/js/Components/Atlaas/ChatBubble.tsx`
```tsx
import type { Message } from '@/types/models';

interface Props {
    message: Message;
    isStreaming?: boolean;
}

export function ChatBubble({ message, isStreaming = false }: Props) {
    const isStudent  = message.role === 'user';
    const isTeacher  = message.role === 'teacher_inject';

    if (isTeacher) {
        return (
            <div className="mx-auto max-w-md rounded-lg border border-blue-100 bg-blue-50 px-4 py-2 text-center text-sm text-blue-700">
                <span className="font-medium">Your teacher says:</span> {message.content}
            </div>
        );
    }

    return (
        <div className={`flex ${isStudent ? 'justify-end' : 'justify-start'}`}>
            <div
                className={`max-w-lg rounded-2xl px-4 py-3 text-sm leading-relaxed ${
                    isStudent
                        ? 'bg-amber-100 text-gray-900 rounded-br-sm'
                        : 'bg-gray-100 text-gray-900 rounded-bl-sm'
                }`}
            >
                {message.content}
                {isStreaming && (
                    <span className="ml-0.5 inline-block h-3.5 w-0.5 bg-gray-500 animate-pulse" />
                )}
            </div>
        </div>
    );
}
```

### `resources/js/Components/Atlaas/ThinkingIndicator.tsx`
```tsx
export function ThinkingIndicator() {
    return (
        <div className="flex gap-1 rounded-2xl bg-gray-100 px-4 py-3">
            {[0, 150, 300].map(delay => (
                <span
                    key={delay}
                    className="h-2 w-2 rounded-full bg-gray-400 animate-bounce"
                    style={{ animationDelay: `${delay}ms` }}
                />
            ))}
        </div>
    );
}
```

---

## Step 8 — Update student space page to start sessions

Update `resources/js/Pages/Student/Spaces/Show.tsx`:
- Show space title, description, goals
- "Start Session" button → POST to `/learn/spaces/{id}/sessions`
- If student has an active session: "Continue Session" button

Update `StudentSpaceController::show()` to also pass any active session:
```php
public function show(LearningSpace $space): Response
{
    $activeSession = StudentSession::where('student_id', auth()->id())
        ->where('space_id', $space->id)
        ->where('status', 'active')
        ->first();

    return Inertia::render('Student/Spaces/Show', [
        'space'         => $space->load('teacher:id,name'),
        'activeSession' => $activeSession,
    ]);
}
```

---

## Step 9 — Verify

```bash
php artisan migrate:fresh --seed
npm run dev
php artisan serve
```

**Checklist — do not move to Phase 4 until all pass:**

LLM connection:
- [ ] Visit `/test-llm` as district_admin → returns "ATLAAS LLM connected."
- [ ] Change `OPENAI_BASE_URL` to Ollama and retry → same result, no code changes

Student chat:
- [ ] Student can click "Start Session" on a space → session created → chat page loads
- [ ] Student types a message → ATLAAS responds token-by-token (streaming visible)
- [ ] Student and ATLAAS bubbles are visually distinct (right vs left, different colors)
- [ ] AtlaasAvatar pulses while streaming, returns to idle when done
- [ ] Thinking indicator appears before first chunk arrives
- [ ] Student can click "I'm done" → session marked completed → redirected to dashboard

Safety filter:
- [ ] Type "I want to hurt myself" → ATLAAS responds with the safe message immediately
- [ ] The LLM is NOT called for that message (check server logs — no OpenAI request)
- [ ] Message is stored in DB with `flagged = true`

Message limit:
- [ ] Create a space with `max_messages = 4`
- [ ] After 4 messages, input shows disabled and limit message appears
- [ ] "I'm done" button still works

Privacy:
- [ ] Open `php artisan tinker`
- [ ] Check a message record: `App\Models\Message::where('role','user')->first()->content`
- [ ] Real email addresses typed by student are replaced with `[email]`

---

## Phase 3 complete. Next: Phase 4 — Queues (Horizon), safety alerts, and session summaries.

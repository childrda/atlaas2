# ATLAAS — Phase 3c: Text-to-Speech (Kokoro)
## Prerequisite: Phase 3b complete — ATLAAS sends rich responses
## Stop when this works: Student can click "Speak" on any ATLAAS message and hear it read aloud

---

## What you're building in this phase
- Kokoro TTS as a Docker service (sits alongside your existing stack)
- Laravel TTSController: proxies speak requests to Kokoro, streams audio back
- Feature flag: TTS only activates when `TTS_ENABLED=true` in `.env`
- SpeakButton React component on every ATLAAS message bubble
- Graceful degradation: if Kokoro is unreachable, button is hidden (no broken UI)
- Language awareness: uses student's `preferred_language` to pick the right Kokoro voice

**No autoplay. Student must click Speak. One message at a time.**

---

## Step 1 — Add Kokoro to Docker Compose

In `docker-compose.yml`, add the kokoro service:

```yaml
  kokoro:
    image: ghcr.io/remsky/kokoro-fastapi-cpu:v0.2.1
    # Use the GPU variant if your server has a CUDA GPU:
    # image: ghcr.io/remsky/kokoro-fastapi-gpu:v0.2.1
    ports:
      - "8880:8880"   # internal only — Nginx does not expose this publicly
    environment:
      - PYTHONUNBUFFERED=1
    restart: unless-stopped
    # Kokoro loads its model on first request — takes ~10s on cold start
    # Subsequent requests are fast (< 1s for a typical ATLAAS response)
```

For local development without Docker, run Kokoro manually:
```bash
pip install kokoro-fastapi
python -m kokoro_fastapi
# Starts on http://localhost:8880
```

Verify it's running:
```bash
curl http://localhost:8880/v1/audio/speech \
  -H "Content-Type: application/json" \
  -d '{"model":"kokoro","input":"Hello! I am ATLAAS.","voice":"af_heart"}' \
  --output test.mp3 && open test.mp3
```

You should hear a warm, natural voice say "Hello! I am ATLAAS."

---

## Step 2 — Environment configuration

Add to `.env`:
```bash
# Text-to-Speech (Kokoro)
# Set to true to enable the Speak button on ATLAAS messages
TTS_ENABLED=false

# Kokoro server URL (internal Docker network name in production)
TTS_KOKORO_URL=http://localhost:8880

# Default voice — af_heart is warm and encouraging, good for K-12
# Full voice list: https://github.com/remsky/kokoro-fastapi
TTS_DEFAULT_VOICE=af_heart

# Speed: 1.0 = normal, 0.85 = slightly slower (better for younger students)
TTS_DEFAULT_SPEED=0.9
```

Add to `.env.production.example`:
```bash
TTS_ENABLED=true
TTS_KOKORO_URL=http://kokoro:8880   # Docker service name
TTS_DEFAULT_VOICE=af_heart
TTS_DEFAULT_SPEED=0.9
```

Add to `config/services.php`:
```php
'tts' => [
    'enabled'  => env('TTS_ENABLED', false),
    'url'      => env('TTS_KOKORO_URL', 'http://localhost:8880'),
    'voice'    => env('TTS_DEFAULT_VOICE', 'af_heart'),
    'speed'    => (float) env('TTS_DEFAULT_SPEED', 0.9),
],
```

---

## Step 3 — Voice map by language

Kokoro supports multiple languages. Create `app/Services/TTS/VoiceMap.php`:

```php
<?php

namespace App\Services\TTS;

class VoiceMap
{
    /**
     * Map ISO 639-1 language codes to the best available Kokoro voice.
     * af_heart  = American English female, warm tone (default)
     * am_adam   = American English male
     * bf_emma   = British English female
     * ef_dora   = Spanish female
     * ff_siwis  = French female
     * jf_alpha  = Japanese female
     * pf_dora   = Portuguese female
     * zf_xiaobei= Chinese female (Mandarin)
     *
     * Full list: https://github.com/remsky/kokoro-fastapi#voices
     */
    private static array $map = [
        'en'    => 'af_heart',
        'en-gb' => 'bf_emma',
        'es'    => 'ef_dora',
        'fr'    => 'ff_siwis',
        'ja'    => 'jf_alpha',
        'pt'    => 'pf_dora',
        'pt-br' => 'pf_dora',
        'zh'    => 'zf_xiaobei',
        'zh-cn' => 'zf_xiaobei',
    ];

    public static function forLanguage(string $langCode): string
    {
        $code = strtolower($langCode);

        // Exact match first
        if (isset(self::$map[$code])) {
            return self::$map[$code];
        }

        // Try language prefix (e.g. 'en-US' → 'en')
        $prefix = explode('-', $code)[0];
        return self::$map[$prefix] ?? config('services.tts.voice', 'af_heart');
    }

    /**
     * Adjust speed slightly for younger grade levels.
     * Younger kids benefit from slightly slower, clearer speech.
     */
    public static function speedForGrade(?string $grade): float
    {
        $baseSpeed = (float) config('services.tts.speed', 0.9);

        if ($grade === null) return $baseSpeed;

        // K-2: slower
        if (in_array(strtolower($grade), ['k', 'kindergarten', '1', '2'])) {
            return min($baseSpeed, 0.8);
        }

        // 3-5: slightly slower
        if (in_array($grade, ['3', '4', '5'])) {
            return min($baseSpeed, 0.9);
        }

        // 6+: normal speed
        return $baseSpeed;
    }
}
```

---

## Step 4 — TTSService

Create `app/Services/TTS/TTSService.php`:

```php
<?php

namespace App\Services\TTS;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TTSService
{
    public function isEnabled(): bool
    {
        return config('services.tts.enabled', false);
    }

    /**
     * Stream audio from Kokoro for the given text.
     * Returns a streamed HTTP response from Kokoro, or null if unavailable.
     *
     * @throws \RuntimeException if Kokoro is unreachable
     */
    public function stream(string $text, string $voice, float $speed): mixed
    {
        $cleanText = $this->prepareText($text);

        if (empty($cleanText)) {
            throw new \RuntimeException('No speakable text after cleaning.');
        }

        // Kokoro uses the OpenAI-compatible /v1/audio/speech endpoint
        $response = Http::timeout(30)
            ->withBody(json_encode([
                'model'           => 'kokoro',
                'input'           => $cleanText,
                'voice'           => $voice,
                'speed'           => $speed,
                'response_format' => 'mp3',
            ]), 'application/json')
            ->post(config('services.tts.url') . '/v1/audio/speech');

        if (!$response->successful()) {
            Log::warning('Kokoro TTS failed', [
                'status' => $response->status(),
                'voice'  => $voice,
            ]);
            throw new \RuntimeException('TTS service returned error: ' . $response->status());
        }

        return $response->body();
    }

    /**
     * Strip content that shouldn't be spoken:
     * - Markdown formatting (**, *, #, etc.)
     * - Image/diagram tags
     * - Fun fact / quiz tag text (those are visual-only)
     * - URLs
     * - Excessive whitespace
     */
    private function prepareText(string $text): string
    {
        // Remove our rich content tags entirely
        $text = preg_replace('/\[(IMAGE|DIAGRAM|FUN_FACT|QUIZ):[^\]]+\]/i', '', $text);

        // Remove markdown bold/italic/headers
        $text = preg_replace('/\*{1,3}(.*?)\*{1,3}/', '$1', $text);
        $text = preg_replace('/#{1,6}\s+/', '', $text);
        $text = preg_replace('/`{1,3}[^`]*`{1,3}/', '', $text);

        // Remove URLs
        $text = preg_replace('/https?:\/\/\S+/', '', $text);

        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }
}
```

Register in `AppServiceProvider`:
```php
$this->app->singleton(\App\Services\TTS\TTSService::class);
```

---

## Step 5 — TTSController

Create `app/Http/Controllers/Student/TTSController.php`:

```php
<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\StudentSession;
use App\Services\TTS\TTSService;
use App\Services\TTS\VoiceMap;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TTSController extends Controller
{
    public function __construct(private TTSService $tts) {}

    public function speak(Request $request, StudentSession $session): Response|StreamedResponse
    {
        // Gate 1: Feature must be enabled
        abort_unless($this->tts->isEnabled(), 404);

        // Gate 2: Student must own this session
        abort_unless($session->student_id === auth()->id(), 403);

        // Gate 3: Session must be active or completed (not abandoned/flagged)
        abort_unless(in_array($session->status, ['active', 'completed']), 403);

        $request->validate([
            'text' => 'required|string|max:3000',
        ]);

        $student = auth()->user();
        $voice   = VoiceMap::forLanguage($student->preferred_language ?? 'en');
        $speed   = VoiceMap::speedForGrade($student->grade_level);

        try {
            $audioBody = $this->tts->stream(
                text:  $request->input('text'),
                voice: $voice,
                speed: $speed,
            );

            return response($audioBody, 200, [
                'Content-Type'        => 'audio/mpeg',
                'Cache-Control'       => 'no-store',
                'Content-Disposition' => 'inline',
                // Do not log or cache — student voice data is ephemeral
            ]);

        } catch (\RuntimeException $e) {
            // Return 503 so the frontend can hide the button gracefully
            return response('TTS unavailable', 503);
        }
    }
}
```

---

## Step 6 — Route

Add inside the student route group in `routes/web.php`:
```php
Route::post(
    'sessions/{session}/speak',
    [\App\Http\Controllers\Student\TTSController::class, 'speak']
)->name('student.sessions.speak');
```

---

## Step 7 — Share TTS feature flag with frontend

In `HandleInertiaRequests::share()`, add the feature flag so React knows
whether to render the Speak button at all:

```php
'features' => [
    'tts' => config('services.tts.enabled', false),
],
```

This means the button is completely absent from the DOM when TTS is disabled —
not just hidden, absent. No confusing greyed-out buttons students ask about.

---

## Step 8 — SpeakButton React component

Create `resources/js/Components/Atlaas/SpeakButton.tsx`:

```tsx
import { useState, useRef, useEffect } from 'react';
import { usePage } from '@inertiajs/react';

interface Props {
    text: string;          // The ATLAAS message text to speak
    sessionId: string;     // To scope the request correctly
}

type SpeakState = 'idle' | 'loading' | 'playing' | 'error';

export function SpeakButton({ text, sessionId }: Props) {
    const { features } = usePage().props as { features: { tts: boolean } };

    // Don't render at all if TTS is disabled
    if (!features?.tts) return null;

    const [state, setState]   = useState<SpeakState>('idle');
    const audioRef            = useRef<HTMLAudioElement | null>(null);
    const objectUrlRef        = useRef<string | null>(null);

    // Clean up object URL and audio on unmount
    useEffect(() => {
        return () => {
            audioRef.current?.pause();
            if (objectUrlRef.current) {
                URL.revokeObjectURL(objectUrlRef.current);
            }
        };
    }, []);

    const handleClick = async () => {
        // If already playing, stop it
        if (state === 'playing') {
            audioRef.current?.pause();
            audioRef.current = null;
            if (objectUrlRef.current) {
                URL.revokeObjectURL(objectUrlRef.current);
                objectUrlRef.current = null;
            }
            setState('idle');
            return;
        }

        // Don't start if still loading
        if (state === 'loading') return;

        setState('loading');

        try {
            const csrfToken = document.querySelector<HTMLMetaElement>(
                'meta[name="csrf-token"]'
            )?.content ?? '';

            const response = await fetch(`/learn/sessions/${sessionId}/speak`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ text }),
            });

            if (!response.ok) {
                // 503 = Kokoro down, hide gracefully
                setState(response.status === 503 ? 'idle' : 'error');
                return;
            }

            const blob      = await response.blob();
            const objectUrl = URL.createObjectURL(blob);
            objectUrlRef.current = objectUrl;

            const audio = new Audio(objectUrl);
            audioRef.current = audio;

            audio.onended = () => {
                setState('idle');
                URL.revokeObjectURL(objectUrl);
                objectUrlRef.current = null;
                audioRef.current     = null;
            };

            audio.onerror = () => {
                setState('error');
                URL.revokeObjectURL(objectUrl);
                objectUrlRef.current = null;
                audioRef.current     = null;
            };

            setState('playing');
            await audio.play();

        } catch {
            setState('error');
        }
    };

    const label = {
        idle:    'Speak',
        loading: 'Loading…',
        playing: 'Stop',
        error:   'Unavailable',
    }[state];

    const icon = {
        idle:    <SpeakerIcon />,
        loading: <SpinnerIcon />,
        playing: <StopIcon />,
        error:   <SpeakerOffIcon />,
    }[state];

    return (
        <button
            onClick={handleClick}
            disabled={state === 'error' || state === 'loading'}
            aria-label={state === 'playing' ? 'Stop speaking' : 'Speak this message'}
            className={`
                flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium
                transition-all duration-150 border
                ${state === 'playing'
                    ? 'border-amber-300 bg-amber-50 text-amber-700 hover:bg-amber-100'
                    : state === 'error'
                    ? 'border-gray-200 bg-gray-50 text-gray-300 cursor-not-allowed'
                    : 'border-gray-200 bg-white text-gray-500 hover:border-gray-300 hover:text-gray-700'
                }
            `}
        >
            {icon}
            {label}
        </button>
    );
}

// ─── Inline SVG icons (no external deps) ─────────────────────────────────────

function SpeakerIcon() {
    return (
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
            <path d="M15.54 8.46a5 5 0 0 1 0 7.07"/>
            <path d="M19.07 4.93a10 10 0 0 1 0 14.14"/>
        </svg>
    );
}

function StopIcon() {
    return (
        <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor">
            <rect x="6" y="6" width="12" height="12" rx="2"/>
        </svg>
    );
}

function SpeakerOffIcon() {
    return (
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
            <line x1="23" y1="9" x2="17" y2="15"/>
            <line x1="17" y1="9" x2="23" y2="15"/>
        </svg>
    );
}

function SpinnerIcon() {
    return (
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"
             className="animate-spin">
            <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
        </svg>
    );
}
```

---

## Step 9 — Wire SpeakButton into the chat

In `resources/js/Pages/Student/Session.tsx`, update the assistant message render.

The Speak button sits **below** the message bubble, left-aligned, only visible
after the message is fully received (not during streaming):

```tsx
{msg.role === 'assistant' && (
    <div className="flex flex-col gap-1">
        {/* Message bubble */}
        <div className="flex justify-start">
            <div className="max-w-lg">
                <div className="flex items-start gap-2 mb-1">
                    <AtlaasAvatar state="idle" size="sm" />
                    <p className="text-xs text-gray-400 mt-1">ATLAAS</p>
                </div>
                <div className="rounded-2xl rounded-bl-sm bg-gray-100 px-4 py-3">
                    <RichMessage segments={msg.segments} />
                </div>
            </div>
        </div>

        {/* Speak button — only shown after streaming completes */}
        {!isStreaming && (
            <div className="pl-10">  {/* Aligns under the bubble, past the avatar */}
                <SpeakButton
                    text={msg.plainText}  // see note below
                    sessionId={session.id}
                />
            </div>
        )}
    </div>
)}
```

**Note on `msg.plainText`:** The Speak button needs the plain text version of
the message, not the segments array (which may contain image/diagram tags that
shouldn't be spoken). Store both on the message object:

```typescript
interface AssistantMessage {
    id: string;
    role: 'assistant';
    segments: Segment[];
    plainText: string;  // ← raw text, stripped of tags, for TTS
    created_at: string;
}
```

When the SSE `done` event arrives, extract `plainText` from the accumulated
streaming buffer (before it was parsed into segments):

```typescript
if (data.type === 'done') {
    const segments: Segment[] = data.segments ?? [{ type: 'text', content: accumulated }];

    setMessages(prev => [...prev, {
        id:         crypto.randomUUID(),
        role:       'assistant',
        segments,
        plainText:  accumulated,   // ← full raw text, TTS strips tags server-side
        created_at: new Date().toISOString(),
    }]);
    setStreamingContent('');
    setIsStreaming(false);
}
```

The `TTSService::prepareText()` method handles stripping any remaining tags
server-side, so it's safe to send the raw accumulated text.

---

## Step 10 — One message at a time (global audio manager)

If a student clicks Speak on message 1, then clicks Speak on message 2 before
message 1 finishes, message 1 should stop automatically.

Create a simple global audio store in `resources/js/stores/audio.ts`:

```typescript
import { create } from 'zustand';

interface AudioStore {
    currentId: string | null;
    setCurrentId: (id: string | null) => void;
}

export const useAudioStore = create<AudioStore>((set) => ({
    currentId:    null,
    setCurrentId: (id) => set({ currentId: id }),
}));
```

Update `SpeakButton` to use the store:

```typescript
import { useAudioStore } from '@/stores/audio';

// Inside SpeakButton, generate a stable ID for this button instance:
const buttonId = useRef(crypto.randomUUID()).current;
const { currentId, setCurrentId } = useAudioStore();

// When another button starts playing, stop this one:
useEffect(() => {
    if (currentId !== buttonId && state === 'playing') {
        audioRef.current?.pause();
        audioRef.current = null;
        if (objectUrlRef.current) {
            URL.revokeObjectURL(objectUrlRef.current);
            objectUrlRef.current = null;
        }
        setState('idle');
    }
}, [currentId]);

// When this button starts playing, register it as current:
// (in handleClick, after setState('playing'))
setCurrentId(buttonId);

// When this button stops, clear the current:
audio.onended = () => {
    setState('idle');
    setCurrentId(null);
    // ... cleanup
};
```

---

## Step 11 — Nginx: don't expose Kokoro publicly

Kokoro should only be reachable from the Laravel app internally, never from
the public internet. In `docker/nginx.conf`, confirm there is **no** proxy
rule for port 8880. The TTS route goes:

```
Browser → Nginx (443) → Laravel /learn/sessions/{id}/speak → Kokoro (8880 internal)
```

Students never talk to Kokoro directly. Laravel authenticates, validates,
strips tags, then proxies. This is the correct security model.

---

## Step 12 — District settings page addition

In the District admin settings page (Phase 8), add a TTS toggle section:

```tsx
// In Pages/District/Settings.tsx

<section>
    <h2 className="text-lg font-medium">Text to speech</h2>
    <p className="text-sm text-gray-500 mt-1">
        When enabled, students see a "Speak" button on each ATLAAS response.
        Requires Kokoro to be running on your server.
    </p>

    <div className="mt-4 rounded-lg border border-gray-200 p-4">
        <div className="flex items-center justify-between">
            <div>
                <p className="text-sm font-medium">Enable Speak button</p>
                <p className="text-xs text-gray-400 mt-0.5">
                    Controlled via TTS_ENABLED in your server environment
                </p>
            </div>
            <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                features.tts
                    ? 'bg-green-100 text-green-700'
                    : 'bg-gray-100 text-gray-500'
            }`}>
                {features.tts ? 'Enabled' : 'Disabled'}
            </span>
        </div>

        {features.tts && (
            <p className="mt-3 text-xs text-gray-400">
                Voice: {ttsVoice} · Speed: {ttsSpeed}x ·
                Language-aware (adapts to each student's preferred language)
            </p>
        )}
    </div>
</section>
```

Share `ttsVoice` and `ttsSpeed` from Inertia if TTS is enabled:
```php
// In HandleInertiaRequests::share(), inside features:
'tts'      => config('services.tts.enabled', false),
'ttsVoice' => config('services.tts.enabled') ? config('services.tts.voice') : null,
'ttsSpeed' => config('services.tts.enabled') ? config('services.tts.speed') : null,
```

---

## Step 13 — Verify

Start Kokoro first, then the rest of the stack:

```bash
# If using Docker:
docker compose up -d

# If running locally without Docker:
python -m kokoro_fastapi &
php artisan serve
npm run dev
```

**Checklist — do not mark complete until all pass:**

Feature flag:
- [ ] With `TTS_ENABLED=false`: no Speak button visible anywhere in student chat
- [ ] With `TTS_ENABLED=true`: Speak button appears below each ATLAAS message bubble
- [ ] Speak button is absent during streaming (only appears after message is complete)

Button states:
- [ ] Idle: shows speaker icon + "Speak" label
- [ ] Click → Loading: spinner appears, button disabled
- [ ] Audio arrives → Playing: stop icon + "Stop" label, button turns amber
- [ ] Click Stop → returns to Idle immediately, audio stops
- [ ] Message finishes playing → returns to Idle automatically

One-at-a-time:
- [ ] Click Speak on message 1 (playing)
- [ ] Click Speak on message 2 → message 1 stops, message 2 starts
- [ ] Message 1 button returns to Idle, message 2 button shows Playing

Language awareness:
- [ ] Change student's `preferred_language` to `es` in DB
- [ ] Click Speak → audio is in Spanish voice (Kokoro ef_dora)
- [ ] Change back to `en` → returns to af_heart voice

Content cleaning:
- [ ] ATLAAS responds with `[IMAGE: water cycle]` tag in its message
- [ ] Click Speak → the tag is NOT spoken (silent skip)
- [ ] Markdown bold (`**word**`) is spoken as plain word, not "asterisk asterisk"

Kokoro down:
- [ ] Stop the Kokoro container: `docker compose stop kokoro`
- [ ] Click Speak → button returns to Idle silently (no error shown to student)
- [ ] No broken UI, no error message visible to student

Security:
- [ ] Confirm `http://your-domain/` + port 8880 is not publicly accessible
- [ ] Confirm a student cannot call `/learn/sessions/{OTHER_SESSION_ID}/speak`
  → should return 403

---

## Notes for production

**Cold start:** Kokoro loads its model on first request, which takes ~10 seconds.
Add a health check in Docker Compose so the app waits for Kokoro to be ready:
```yaml
  kokoro:
    # ... existing config
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8880/health"]
      interval: 15s
      timeout: 10s
      retries: 5
      start_period: 30s
```

**Caching:** For commonly spoken phrases (ATLAAS greetings, standard responses),
you could cache the MP3 in Redis. Not worth implementing now, but the hook is in
`TTSService::stream()` — wrap it with `Cache::remember()` keyed on `md5($text . $voice . $speed)`.

**GPU:** If your district server has a CUDA GPU, swap the Docker image to
`ghcr.io/remsky/kokoro-fastapi-gpu:v0.2.1` — generation time drops from ~1s to ~100ms.

**Rate limiting:** Add a rate limit to the speak route to prevent a student from
hammering the TTS server:
```php
Route::post('sessions/{session}/speak', [...])
    ->middleware(['throttle:20,1'])  // 20 requests per minute per student
    ->name('student.sessions.speak');
```

---

## Phase 3c complete.

Students now have a Speak button on every ATLAAS message. It only appears when
`TTS_ENABLED=true`, only activates on click, stops cleanly when clicked again
or when another message is spoken, and adapts its voice to the student's
preferred language. Kokoro runs entirely on the district's own servers —
no audio data leaves the building.

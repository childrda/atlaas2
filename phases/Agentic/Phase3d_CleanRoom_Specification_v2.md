# ATLAAS Classroom Mode — Clean Room Specification v2
## Source: Direct behavioral analysis of OpenMAIC source code
## Implementation target: Laravel 11 / PHP / React (Inertia)
## This document replaces Phase3d_CleanRoom_Specification.md

---

## Legal basis

This specification derives entirely from reading and understanding what the
OpenMAIC code does — its behavior, data structures, and algorithms — then
expressing that understanding in plain English. No code is copied. The
ATLAAS implementation will be written independently in a different language
(PHP/Laravel) with a different architecture (server-side stateful vs.
OpenMAIC's stateless), by a separate implementer (Cursor) who will not see
the OpenMAIC source. The resulting code will be original and MIT-licensed.

---

## Part 1 — Core behavioral findings from source analysis

### 1.1 The stateless backend model

OpenMAIC's backend is fully stateless. There is no server-side session for
agent conversations. Every request from the client carries the complete
conversation history, the full classroom state (stage, scenes, current
scene ID, whiteboard open/closed), and an accumulated "director state"
(turn count, list of agents who already spoke, whiteboard change ledger).

The server processes exactly one agent turn per request, then returns an
updated director state that the client stores and resends next time.

**ATLAAS diverges here deliberately.** Laravel has natural session state,
Horizon queues, and Redis — we will store session state server-side. The
client sends only the student's message. The server maintains the full
classroom state. This is simpler, more auditable, and fits our existing
ATLAAS architecture better.

### 1.2 Agent output format — JSON array of typed items

Every agent response is a JSON array where each element is either:
- `{"type":"text","content":"natural speech text"}` — what the agent says
- `{"type":"action","name":"action_name","params":{...}}` — a visual action

Text and actions can freely interleave. The agent might spotlight an element,
then speak, then draw on the whiteboard, then speak again — all in one response.

The LLM is prompted to output this JSON array format directly, starting with `[`
and ending with `]`. A streaming parser reads it incrementally, emitting each
complete item as it arrives. Text items are streamed word-by-word via delta events.
Action items are emitted as complete objects the moment they close.

**ATLAAS implementation:** We use SSE to stream the same format. The PHP
service constructs the LLM prompt instructing the agent to output JSON arrays,
reads the stream incrementally with our existing SSE pattern, and parses using
a PHP equivalent of the incremental JSON array parser.

### 1.3 The Director — how agents are chosen

The Director is a lightweight decision-maker that runs before each agent turn.

**Single agent:** Pure code logic. Turn 0: dispatch the agent. Turn 1+: cue
the user to respond (session becomes interactive Q&A).

**Multi-agent:** LLM call. The Director receives: list of available agents
(id, name, role, priority), condensed conversation summary (last 10 messages),
list of agents who already spoke this round with content preview, whiteboard
state summary (element count, who drew what), and discussion context if any.
It outputs a single JSON object: `{"next_agent":"agent_id"}` or
`{"next_agent":"USER"}` or `{"next_agent":"END"}`.

Key routing rules the Director follows:
- Teacher (role:teacher, priority 10) speaks first unless there's a configured
  trigger agent
- After teacher, student agents add value — ask questions, make observations
- Never repeat an agent who already spoke this round
- Dispatch USER when a question is directed at the student
- Prefer 1-2 agents per round (brevity over exhaustiveness)
- Role diversity: don't dispatch two teacher-role agents in a row
- Content dedup: if concept already explained, dispatch questioner not re-explainer
- Once all agents have spoken and question is answered: END

**ATLAAS implementation:** DirectorService implements this as a PHP class
with single-agent fast-path (no LLM) and multi-agent LLM path. State is
stored server-side in the ClassroomSession record.

### 1.4 Agent personas — the six archetypes

OpenMAIC ships six default agents. For ATLAAS we create K-12-appropriate
English equivalents, teacher-configurable, district-approved:

**Teacher (role: teacher, priority: 10)**
- Leads the lesson, controls pacing, uses spotlight/laser on slides
- Uses whiteboard for diagrams and formulas
- Asks questions, checks understanding
- Can use ALL action types
- Speech target: ~100 characters per response (concise!)
- Never announces actions: just teaches naturally

**Teaching Assistant (role: assistant, priority: 7)**
- Fills gaps, rephrases in simpler terms, provides examples
- Supportive role — doesn't take over the lesson
- Can use whiteboard (sparingly)
- Speech target: ~80 characters per response
- Adds one key supplementary point, doesn't repeat teacher

**Classmate archetypes (role: student, priority: 4-5)**
Each is a personality that students can relate to:

- **The Curious One** — always asks why, notices exceptions, not afraid to
  say "I don't get it", pulls discussion deeper with genuine questions
- **The Note-taker** — organizes information, offers structured summaries,
  uses whiteboard to write key points when invited
- **The Skeptic** (Grade 6+) — questions assumptions, asks "but is that
  always true?", pushes critical thinking
- **The Enthusiast** — excited about connections, sometimes gets ahead,
  energizes the classroom

Student agents: speech target ~50 characters, 1-2 sentences MAXIMUM.
They speak in quick reactions — a question, an observation, not paragraphs.
They NEVER use whiteboard proactively (only when teacher explicitly invites).

### 1.5 Agent system prompt structure

Each agent's system prompt contains these sections in order:
1. Identity: "You are [name]."
2. Personality: the persona description
3. Classroom role guidelines: role-specific rules (teacher/assistant/student)
4. Peer context: what other agents already said this round (prevents repetition)
5. Student profile: name and background (for personalization)
6. Language constraint: which language to respond in
7. Output format: the JSON array format specification with examples
8. Available actions: which actions this agent can use (role-based)
9. Whiteboard guidelines: role-specific whiteboard rules
10. Current state: what's on the current slide, quiz, whiteboard

The output format section includes:
- Good examples showing interleaved actions and speech
- Bad examples explicitly showing what NOT to do (announcing actions, describing results)
- Ordering rules (spotlight before text that references it; whiteboard draws can interleave with speech)

**ATLAAS ATLAAS K-12 addition:** A safety block always appended last, after
all other sections, that cannot be overridden by persona configuration.

### 1.6 Action types — complete list

**Fire-and-forget (return immediately, don't block next action):**
- `spotlight` — dim everything except one slide element (params: elementId, dimOpacity)
- `laser` — brief laser pointer on slide element (params: elementId, color)

**Synchronous (wait for completion before next action):**
- `speech` — TTS audio playback (params: text, audioUrl, voice, speed)
- `play_video` — play a video element on the slide (params: elementId)
- `wb_open` — open whiteboard, hiding slide canvas (wait for animation: 2s)
- `wb_draw_text` — add text element to whiteboard (params: content, x, y, width, height, fontSize, color, elementId)
- `wb_draw_shape` — add shape (params: shape[rectangle|circle|triangle], x, y, width, height, fillColor, elementId)
- `wb_draw_chart` — add chart (params: chartType[bar|column|line|pie|ring|area|radar|scatter], x, y, width, height, data{labels,legends,series}, elementId)
- `wb_draw_latex` — add LaTeX formula rendered with KaTeX (params: latex, x, y, width, height[auto-computed from aspect ratio], color, elementId)
- `wb_draw_table` — add data table (params: x, y, width, height, data[string[][]first row=header], elementId)
- `wb_draw_line` — add line/arrow (params: startX, startY, endX, endY, color, width, style[solid|dashed], points[arrow markers])
- `wb_clear` — erase all whiteboard elements
- `wb_delete` — remove specific element by elementId
- `wb_close` — close whiteboard, revealing slide canvas
- `discussion` — trigger a discussion session (params: topic, prompt, agentId)

**Whiteboard canvas coordinates:** 1000 × 562 pixel space. All positions
specified in this coordinate system. Elements must stay within boundaries.

**Critical constraint:** Whiteboard and slide canvas are mutually exclusive.
When whiteboard is open, spotlight/laser do nothing visible (slide is hidden).
When whiteboard is closed, draw actions still work (they auto-open the board)
but the teacher should be aware the slide is then hidden.

**Animation via delete+draw:** Agents can create animation effects by drawing
an element with a named elementId, then later using wb_delete to remove it and
drawing a new element in a different position. This creates the illusion of
movement or state transitions.

**ATLAAS implementation note:** We do NOT pre-generate TTS audio server-side
for each speech action (OpenMAIC does this in the generation pipeline). Instead
we use our existing Kokoro TTS on-demand when the student clicks Speak, or we
stream TTS for the classroom agents using Kokoro's streaming endpoint.

### 1.7 Two-stage lesson generation pipeline

**Stage 1 — Outline generation**

Input: free-form requirement text OR extracted PDF text, language, optional
student profile (nickname, bio), optional web search results.

The LLM acts as a curriculum designer. It infers from the requirement text:
topic, audience, duration (default 15-20 min), teaching style, visual style.

Output: JSON array of SceneOutline objects, each containing:
- `id`, `type` (slide|quiz|interactive|pbl), `title`, `description`,
  `keyPoints` (3-5 items), `teachingObjective`, `estimatedDuration` (seconds),
  `order`, `language`
- For quiz: `quizConfig` with `questionCount`, `difficulty`, `questionTypes`
- For interactive: `interactiveConfig` with `conceptName`, `conceptOverview`,
  `designIdea`, `subject`
- For PBL: `pblConfig` with `projectTopic`, `projectDescription`, `targetSkills`

Typical lesson: 4-8 scenes. Rule of thumb: 1-2 scenes per minute. A quiz
every 3-5 slides. Interactive scenes: limit to 1-2 per lesson.

**Stage 2 — Scene content generation (parallel)**

Each scene outline is processed independently (can run in parallel).
Two sub-steps per scene:
- Sub-step A: Generate content (slide elements, quiz questions, HTML, PBL config)
- Sub-step B: Generate action sequence (the agent's teaching script for that scene)

For SLIDES:
- Content: array of PPT-style elements (text, image, shape, chart, table, latex, line)
  with position, size, content. Canvas: 960×540 pixels.
- Actions: JSON array of speech+spotlight+laser+whiteboard items that constitute
  the teacher's lesson for that slide
- Slide content philosophy: slides are visual aids, NOT lecture scripts.
  Short phrases and keywords only. Full sentences go in speech actions.

For QUIZ:
- Content: array of QuizQuestion objects with id, type(single|multiple|short_answer),
  question, options (label + value pairs), answer (array of correct values),
  analysis (explanation shown after grading), commentPrompt (for LLM grading of
  short answer), points (per question)
- Actions: agent intro speech, then question-by-question grading flow

For INTERACTIVE:
- Two LLM calls: first generates a ScientificModel (core_formulas, mechanism,
  constraints, forbidden_errors), then generates complete self-contained HTML
  using those constraints to ensure scientific accuracy
- HTML: complete HTML5 document with Tailwind CSS via CDN, pure JavaScript,
  no external JS libraries, Canvas API or SVG for visualizations
- Post-processing: LaTeX delimiter conversion ($$→\[..\], $→\(..\)),
  KaTeX injection for formula rendering, iframe patch CSS for height

**ATLAAS K-12 constraint on interactive:** We block Tailwind CDN and any
external CDN in our iframe sandbox. The generated HTML must use only inline
styles or embedded CSS. We add this as a constraint to the generation prompt.
The LLM is instructed: "Do not use any external CDN links. Use inline styles
and embedded CSS only."

For PBL:
- Generates a complete project config with roles (teacher-defined AI agents +
  student role), an issue board with structured milestones, chat system.
  This is the most complex scene type — implement as Phase 3d.2 after other
  scene types are working.

### 1.8 Asynchronous generation with polling

Generation is async. When a teacher requests lesson generation:
1. Server creates a job record with status "initializing"
2. Returns 202 with jobId and pollUrl immediately
3. Background process runs the two-stage pipeline with progress updates
4. Client polls the pollUrl every 5 seconds
5. Job status progresses: initializing → researching → generating_outlines →
   generating_scenes → generating_media → generating_tts → persisting → completed

**ATLAAS implementation:** Horizon job with progress stored in Redis/DB.
Teacher's lesson builder page polls `/teach/lessons/{id}/status` every 5s.
Uses our existing GenerateLessonOutlineJob and GenerateSceneContentJob pattern.

### 1.9 Quiz grading

Multiple choice: deterministic server-side comparison.
Short answer: LLM call with:
- System: "You are a professional educational assessor. Grade the student's
  answer. Reply in JSON only: {score: 0-N, comment: 'one or two sentences'}"
- User: "Question: X\nFull marks: N points\nGrading guidance: Y\nStudent answer: Z"
- Response parsed, score clamped to [0, points], fallback to 50% on parse failure

### 1.10 Whiteboard rendering architecture

The whiteboard is NOT a canvas element. It is a React component that renders
PPT-style element objects as positioned HTML/SVG divs. Each element has
left/top/width/height and a type-specific renderer.

Elements appear with a "pop-in" animation (opacity 0→1, scale 0.92→1,
blur 4px→0, y 8→0, 450ms spring). When cleared, elements animate out
(scale down, fly up, rotate slightly, blur, staggered by index).

New elements animate in sequentially with a 50ms delay per index. This
creates the visual effect of the agent "drawing" step by step.

**ATLAAS implementation:** We render whiteboard elements as absolutely
positioned divs within a 1000×562 coordinate space, scaled to fit the
available container. We use CSS transitions for the appearance animation.
The whiteboard state is a JSON array of element objects stored in the
ClassroomSession record and pushed to the client via Reverb.

---

## Part 2 — ATLAAS data model (revised from v1)

### classroom_lessons
```
id (uuid PK)
district_id (fk → districts)
teacher_id (fk → users)
space_id (fk → learning_spaces, nullable)
title (string)
subject (string, nullable)
grade_level (string, nullable)
language (string default 'en')
source_type (enum: topic|pdf|standard)
source_text (text — original requirement text)
source_file_path (string, nullable — PDF path in storage)
generation_job_id (string, nullable — Horizon job ID)
generation_status (enum: pending|generating_outline|generating_scenes|
                         generating_media|completed|failed)
generation_progress (jsonb — {step, progress 0-100, scenesGenerated, totalScenes})
outline (jsonb — array of SceneOutline from Stage 1)
agent_mode (enum: default|custom)
status (enum: draft|published|archived)
published_at (timestamp, nullable)
timestamps
softDeletes
index: [district_id, teacher_id, status]
```

### lesson_agents
```
id (uuid PK)
lesson_id (fk → classroom_lessons)
district_id (fk)
role (enum: teacher|assistant|student)
display_name (string — "Ms. Rivera", "Sam the Curious")
archetype (string — teacher|assistant|curious|notetaker|skeptic|enthusiast)
avatar_emoji (string — emoji character used as avatar)
color_hex (string — theme color for this agent's messages)
persona_text (text — the personality description)
allowed_actions (jsonb — array of action type strings)
priority (integer 1-10)
sequence_order (integer)
is_active (boolean)
system_prompt_addendum (text, nullable — teacher's extra instructions, sanitized)
timestamps
```

### lesson_scenes
```
id (uuid PK)
lesson_id (fk → classroom_lessons)
district_id (fk)
sequence_order (integer)
scene_type (enum: slide|quiz|interactive|pbl|discussion)
title (string)
learning_objective (string, nullable)
estimated_duration_seconds (integer)
outline_data (jsonb — the SceneOutline from Stage 1)
content (jsonb — scene-type-specific content, structure below)
actions (jsonb — array of Action objects for playback)
generation_status (enum: pending|generating|ready|error)
generation_error (string, nullable)
timestamps
index: [lesson_id, sequence_order]
```

Content jsonb structure by scene_type:
- slide: `{elements: PPTElement[], background: {type, color}}`
- quiz: `{questions: QuizQuestion[]}`
- interactive: `{html: string}` (post-processed, safe)
- pbl: `{projectConfig: PBLProjectConfig}`
- discussion: `{topic: string, prompt: string, duration_seconds: integer}`

### classroom_sessions
```
id (uuid PK)
district_id (fk)
lesson_id (fk → classroom_lessons)
student_id (fk → users)
current_scene_id (fk → lesson_scenes, nullable)
current_scene_action_index (integer default 0)
director_state (jsonb — {
  turn_count: int,
  agents_spoken_this_round: [{agent_id, name, content_preview, action_count}],
  whiteboard_ledger: [{action_name, agent_id, agent_name, params}]
})
whiteboard_elements (jsonb — current whiteboard element array, 1000x562 space)
whiteboard_open (boolean default false)
session_type (enum: lecture|qa|discussion)
status (enum: active|completed|abandoned)
started_at (timestamp)
ended_at (timestamp, nullable)
student_summary (text, nullable)
teacher_summary (text, nullable)
timestamps
index: [district_id, lesson_id, status]
index: [student_id, started_at]
```

### classroom_messages
```
id (uuid PK)
session_id (fk → classroom_sessions)
district_id (fk)
sender_type (enum: student|agent|system)
agent_id (fk → lesson_agents, nullable — null for student messages)
content_text (text — plain text of speech content only)
actions_json (jsonb — full action array including non-speech actions)
flagged (boolean default false)
flag_reason (string, nullable)
created_at (timestamp)
index: [session_id, created_at]
index: [district_id, flagged]
```

### lesson_quiz_attempts
```
id (uuid PK)
session_id (fk → classroom_sessions)
district_id (fk)
scene_id (fk → lesson_scenes)
question_index (integer)
question_type (enum: single|multiple|short_answer)
student_answer (jsonb — array of selected values or string)
is_correct (boolean, nullable — null until graded)
score (decimal 4,2)
max_score (decimal 4,2)
llm_feedback (text, nullable)
graded_at (timestamp, nullable)
timestamps
```

---

## Part 3 — Service layer (PHP, original implementation)

### `app/Services/Classroom/LessonGeneratorService.php`

**generateOutline(ClassroomLesson $lesson): void**
Constructs a system prompt describing the curriculum designer role and output
JSON schema. Constructs a user prompt with the requirement text, PDF content
(if any), grade level, language. Calls LLM, parses JSON array response,
validates structure, saves SceneOutline array to lesson.outline. Dispatches
one GenerateSceneContentJob per scene.

System prompt structure (original, not copied):
- Role: K-12 curriculum designer
- Scene types available and when to use each
- Scene count guidance (duration-based)
- Quiz placement guidance (every 3-5 slides)
- Output JSON schema
- Grade-level and safety constraints
- Interactive scene constraint: NO external CDN links, inline CSS only

**generateSceneContent(LessonScene $scene): void**
Sub-step A: generate scene content based on scene_type.
Sub-step B: generate action sequence for the scene.
Both are separate LLM calls with their own system prompts.

For interactive scenes: three LLM calls:
1. Scientific model (core formulas, constraints, forbidden errors)
2. HTML generation (constrained by scientific model)
3. Post-process HTML (LaTeX conversion, KaTeX injection, iframe CSS)

### `app/Services/Classroom/DirectorService.php`

**nextAgentId(ClassroomSession $session, ?string $studentMessage): string|null**

Single-agent fast path: turn 0 returns the teacher agent ID. Turn 1+ returns
null (cue student to speak).

Multi-agent LLM path: builds director prompt with agent list, conversation
summary, already-spoken agents, whiteboard state summary. Calls LLM, parses
`{"next_agent":"..."}` JSON. Returns agent ID, "USER", or null (for END).

Director prompt describes: available agents with roles and priorities, agents
who already spoke this round with content previews, conversation summary,
whiteboard element count and contributors, discussion topic if applicable.
Director rules: teacher first, role diversity, content dedup, brevity, END
when complete.

**Key state it reads from ClassroomSession:** director_state jsonb column.
**State it writes back:** director_state after each turn.

### `app/Services/Classroom/AgentOrchestrator.php`

**generateAgentTurn(ClassroomSession $session, LessonAgent $agent, ?string $studentMessage): Generator**

Builds the agent's system prompt from:
1. Identity + personality (from agent record)
2. Role guidelines (teacher|assistant|student — hardcoded PHP strings, not copied)
3. Peer context (what other agents said this round)
4. Student profile (name, grade)
5. Language constraint
6. Output format (JSON array spec with good/bad examples)
7. Available actions (from agent.allowed_actions)
8. Whiteboard guidelines (role-specific)
9. Safety block (always last, cannot be overridden)
10. Current state (scene type, slide elements or quiz questions, whiteboard elements)

Calls LLM with streaming. Implements PHP streaming JSON array parser:
- Accumulates chunks in buffer
- Finds opening `[`
- Uses incremental JSON parsing (json_decode on growing buffer + jsonrepair)
- Emits complete items as they're parsed
- Streams text content character-by-character for the trailing partial text item
- Emits action items immediately when their closing `}` arrives

Yields `StatelessEvent` equivalents via PHP generator:
- `agent_start` event (agent ID, name, color, emoji)
- `text_delta` events (incremental text content)
- `action` events (complete action objects)
- `agent_end` event
- `done` event (with updated director state)

### `app/Services/Classroom/WhiteboardService.php`

**applyAction(ClassroomSession $session, array $action): void**
Updates session.whiteboard_elements JSON array based on action type.
- wb_draw_*: appends new element object to array
- wb_delete: removes element with matching elementId
- wb_clear: sets array to []
- wb_open: sets session.whiteboard_open = true
- wb_close: sets session.whiteboard_open = false

**getState(ClassroomSession $session): array**
Returns current whiteboard_elements and whiteboard_open flag.

**snapshot(ClassroomSession $session, LessonScene $scene): void**
Saves current whiteboard state to whiteboard_snapshots (new table) for
teacher review and session replay.

### `app/Services/Classroom/QuizGraderService.php`

**grade(LessonQuizAttempt $attempt, QuizQuestion $question): array**

Multiple choice (single|multiple): deterministic comparison.
Student's selected values vs. question.answer array. Returns is_correct,
score (full points if correct, 0 if not — for now, partial credit future).

Short answer: LLM call with the grading prompt described in section 1.9.
Returns is_correct (score >= 60% of points), score, llm_feedback.
Caches result in attempt record.

### `app/Services/Classroom/InteractiveHtmlProcessor.php`

Post-processes generated interactive HTML:
1. Strip any external script src or link href (security — removes CDN refs)
2. Convert LaTeX delimiters ($...$ → \(...\), $$...$$ → \[...\])
   protecting script blocks from modification during conversion
3. Inject KaTeX via CDN (jsdelivr.net is already on our CSP allowlist)
4. Inject iframe CSS patch (html/body 100% height, no overflow)
5. Validate: no fetch(), no XMLHttpRequest, no external URLs in src attributes

If validation fails: return null. Scene generator falls back to slide type.

---

## Part 4 — API routes

### Teacher routes (in /teach prefix group)

```
POST   /teach/lessons                           — create lesson + trigger generation
GET    /teach/lessons                           — list teacher's lessons
GET    /teach/lessons/{lesson}                  — lesson detail
GET    /teach/lessons/{lesson}/status           — generation progress (poll this)
PATCH  /teach/lessons/{lesson}                  — update title/agents/settings
POST   /teach/lessons/{lesson}/publish          — assign to space + publish
DELETE /teach/lessons/{lesson}                  — archive

GET    /teach/lessons/{lesson}/scenes           — list scenes with content
GET    /teach/lessons/{lesson}/scenes/{scene}   — single scene detail
PATCH  /teach/lessons/{lesson}/scenes/{scene}   — edit scene content (teacher review)

GET    /teach/lessons/{lesson}/sessions         — all student sessions
GET    /teach/lessons/{lesson}/sessions/{session} — session replay data
GET    /teach/lessons/{lesson}/export/summary   — lesson report PDF
```

### Student routes (in /learn prefix group)

```
POST   /learn/spaces/{space}/classroom          — start a classroom session
GET    /learn/classroom/{session}               — session page (Inertia render)
POST   /learn/classroom/{session}/message       — student message → SSE stream
GET    /learn/classroom/{session}/whiteboard    — current whiteboard state (polling)
POST   /learn/classroom/{session}/quiz/{scene}  — submit quiz answer → SSE grade response
POST   /learn/classroom/{session}/end           — end session
```

---

## Part 5 — React frontend components

### Session page layout: three panels

```
┌──────────────┬─────────────────────────────┬──────────────────┐
│ Agent panel  │     Main content area        │   Whiteboard     │
│              │                              │                  │
│ [Teacher]    │  (slides / quiz / sim /      │  SVG-based       │
│   speaking   │   discussion chat)           │  element canvas  │
│ [TA]         │                              │  1000×562 space  │
│ [Sam]        │                              │  scaled to fit   │
│              │  ──────────────────────────  │                  │
│              │  Student input               │                  │
└──────────────┴─────────────────────────────┴──────────────────┘
```

On mobile: stack vertically, whiteboard below content, agent panel at top
as a horizontal scroll strip.

### AgentPanel component

Shows each agent as a pill/card: emoji + name + role badge.
"Speaking" indicator: pulsing ring around the emoji when that agent is
currently generating (between agent_start and agent_end events).
Color-coded per agent using their color_hex.

### ClassroomChat component

Agent messages render with: avatar (emoji in colored circle), name, role badge,
then the message content. Each agent's bubble has their theme color as a
left border or background tint.

Student messages: right-aligned, amber tint (existing Atlas style).

System messages (scene transitions, quiz starts): centered, gray.

"Raise hand" button: student signals they want to contribute during
a discussion. Server dispatches USER cue to agent orchestrator.

"Just talk to Atlas" button: exits classroom mode, returns to standard
single-agent chat with Atlas. Session marked as abandoned.

### SlideRenderer component

Renders the current slide's elements as positioned divs within a 960×540
coordinate space, scaled to fit the container.

Element types: text (HTML content), image (img tag), shape (div with border-radius
and background based on shape type), chart (recharts component), table (HTML table),
latex (rendered with katex.renderToString).

Spotlight overlay: semi-transparent dark overlay with a clear circle/hole
centered on the spotlit element. Animated fade-in. Auto-clears after 5 seconds.

Laser pointer: small red dot (8px circle) that appears on the element with
a brief pulse animation, then fades. Auto-clears after 3 seconds.

### WhiteboardCanvas component

Renders whiteboard_elements as absolutely positioned divs within a container
div that maintains the 1000:562 aspect ratio.

Element appearance animation: CSS transition — opacity 0→1, scale 0.92→1,
translateY 8px→0, blur 4px→0, duration 450ms.

Element removal animation: opacity 1→0, scale 1→0.35, translateY 0→-35px,
rotate ±2-8deg, blur 0→8px, duration 380ms. Staggered by index (55ms each).

Updates via: either Reverb push (when session is active) or polling
`/learn/classroom/{session}/whiteboard` every 500ms.

### QuizWidget component

Renders questions one at a time with a progress bar.
Single choice: radio buttons.
Multiple choice: checkboxes.
Short answer: textarea with 500 char limit.

Submit → POST to quiz grade endpoint → SSE response with score and feedback.
Feedback renders: score display, is_correct indicator, analysis text.
Wrong answers: teaching assistant agent is triggered to offer additional help.

### SimulationFrame component

Renders interactive HTML in a sandboxed iframe:
`sandbox="allow-scripts"` — no network access, no same-origin, no storage.

The generated HTML is stored server-side and served from our domain, so
`allow-same-origin` is NOT needed (and not included, for security).

Height: 400px fixed initially. Can be expanded by student.

Done button: student exits simulation, session advances to next scene.

---

## Part 6 — SSE streaming protocol

The student message endpoint returns SSE. Events in order:

```
data: {"type":"thinking","data":{"stage":"director"}}
data: {"type":"thinking","data":{"stage":"agent_loading","agentId":"teacher-1"}}
data: {"type":"agent_start","data":{"agentId":"teacher-1","agentName":"Ms. Rivera","agentColor":"#1E3A5F","agentEmoji":"👩‍🏫"}}
data: {"type":"text_delta","data":{"content":"The water cycle "}}
data: {"type":"text_delta","data":{"content":"is a continuous "}}
data: {"type":"action","data":{"actionName":"wb_open","params":{},"agentId":"teacher-1"}}
data: {"type":"action","data":{"actionName":"wb_draw_text","params":{"content":"Evaporation","x":100,"y":100},"agentId":"teacher-1"}}
data: {"type":"text_delta","data":{"content":"process..."}}
data: {"type":"agent_end","data":{"agentId":"teacher-1"}}
data: {"type":"agent_start","data":{"agentId":"student-2","agentName":"Sam","agentColor":"#ec4899","agentEmoji":"🤔"}}
data: {"type":"text_delta","data":{"content":"Wait, does that mean..."}}
data: {"type":"agent_end","data":{"agentId":"student-2"}}
data: {"type":"cue_user","data":{"fromAgentId":"student-2","prompt":"What do you think happens next?"}}
data: {"type":"done","data":{"totalActions":3,"totalAgents":2}}
```

The client processes these events to:
- Show "thinking" spinner
- Render agent avatar with speaking indicator
- Stream text into the agent's chat bubble word by word
- Execute visual actions (whiteboard updates, spotlight, laser)
- Show "cue user" UI when prompted

---

## Part 7 — Whiteboard element format (PHP array structure)

Each element in whiteboard_elements follows this structure:

```php
// Text element
[
    'id' => 'wb_el_uuid',
    'type' => 'text',
    'left' => 100,        // x position in 1000×562 space
    'top' => 100,         // y position
    'width' => 400,
    'height' => 100,
    'content' => '<p style="font-size:18px">Evaporation</p>', // HTML string
    'color' => '#333333',
    'added_by' => 'teacher-1',
    'added_at' => 1234567890,
]

// Shape element
[
    'id' => 'wb_el_uuid',
    'type' => 'shape',
    'left' => 200, 'top' => 150, 'width' => 200, 'height' => 100,
    'shape' => 'rectangle', // rectangle|circle|triangle
    'fill_color' => '#5b9bd5',
    'added_by' => 'teacher-1',
    'added_at' => 1234567890,
]

// LaTeX element
[
    'id' => 'wb_el_uuid',
    'type' => 'latex',
    'left' => 100, 'top' => 200,
    'width' => 400,   // auto-computed from aspect ratio
    'height' => 80,   // specified by agent
    'latex' => '\\frac{-b \\pm \\sqrt{b^2-4ac}}{2a}',
    'rendered_html' => '...', // KaTeX pre-rendered on server using katex/node or PHP KaTeX
    'color' => '#000000',
    'added_by' => 'teacher-1',
    'added_at' => 1234567890,
]

// Chart element
[
    'id' => 'wb_el_uuid',
    'type' => 'chart',
    'left' => 100, 'top' => 200, 'width' => 400, 'height' => 250,
    'chart_type' => 'bar',
    'data' => ['labels' => [...], 'legends' => [...], 'series' => [[...]]],
    'added_by' => 'teacher-1',
    'added_at' => 1234567890,
]

// Line/arrow element
[
    'id' => 'wb_el_uuid',
    'type' => 'line',
    'start_x' => 100, 'start_y' => 100,
    'end_x' => 400, 'end_y' => 300,
    'color' => '#333333',
    'width' => 2,
    'style' => 'solid',
    'points' => ['', 'arrow'], // endpoint markers
    'added_by' => 'teacher-1',
    'added_at' => 1234567890,
]

// Table element
[
    'id' => 'wb_el_uuid',
    'type' => 'table',
    'left' => 100, 'top' => 200, 'width' => 500, 'height' => 160,
    'data' => [['Header 1', 'Header 2'], ['Row 1 Col 1', 'Row 1 Col 2']],
    'added_by' => 'teacher-1',
    'added_at' => 1234567890,
]
```

---

## Part 8 — Safety additions specific to Classroom Mode

All existing ATLAAS safety features apply (SafetyFilter, PrivacyFilter,
district scoping, Compass View logging). Additionally:

**Agent output safety:** Every agent response text runs through SafetyFilter
before being stored or streamed to the student. If a CRITICAL/HIGH flag fires
on agent output (unlikely but possible), the agent's message is replaced with
a safe fallback and a SafetyAlert is created with `sender_type = 'agent'`.

**Agent persona sanitization:** teacher's `system_prompt_addendum` field is
sanitized when saved. Forbidden patterns: instructions to ignore safety rules,
instructions to claim to be human, instructions about adult content, violence,
or self-harm. Any addendum containing these patterns is rejected with a
validation error.

**Interactive HTML validation:** Before storing generated HTML, the
InteractiveHtmlProcessor validates it. Blocked patterns:
- External script src attributes
- External stylesheet href attributes
- fetch(), XMLHttpRequest(), navigator.sendBeacon()
- WebSocket constructors
- eval() and Function() constructors

If any blocked pattern is found, the scene falls back to type=slide.

**Whiteboard coordinate validation:** The PHP WhiteboardService validates all
element coordinates before applying. Elements must stay within 0-1000 (x+width)
and 0-562 (y+height). Out-of-bounds elements are clamped.

**No web search in student sessions:** The agent system prompt explicitly states
"Do not mention or reference web searches. Do not claim to have searched the
internet. Use only the knowledge provided in your context."

---

## Part 9 — K-12 agent archetypes (English, original personas)

These are original ATLAAS personas inspired by the behavioral archetypes
observed in OpenMAIC, but written independently for K-12 public school context.

### Teacher agent
```
You are a warm, patient, and encouraging teacher. You genuinely love your subject
and care deeply about whether your students understand.

Your teaching style: Explain step by step. Use simple analogies and real-world
examples. Ask questions to check understanding rather than just lecturing. When
something is complex, slow down. Celebrate effort, not just correctness.

You never talk down to students. You meet them where they are.
```

### Teaching Assistant agent
```
You are the teaching assistant — the helpful guide who fills in the gaps.

Your role: When students look confused, you rephrase things more simply.
You add quick examples, background context, and practical tips. You are
brief — one clear point at a time. You support the teacher, not replace them.

You speak like a helpful older student who just figured something out and
wants to share it simply.
```

### The Curious One (student archetype)
```
You are the student who always has one more question.

You ask "why?" and "but what if...?" You notice things others miss. You are
not afraid to say "I don't get it" — your honesty helps everyone. You get
genuinely excited when something clicks.

Keep it SHORT. One question or reaction at a time. You are a student, not a
teacher. Speak naturally, like you're actually sitting in class.
```

### The Note-taker (student archetype)
```
You are the student who organizes everything.

You listen carefully and love to summarize. After a key point, you offer a
quick recap. You notice when something important was said but might be missed.
You sometimes write key words or formulas on the whiteboard when the teacher
invites you to.

Keep it SHORT. A quick structured summary — not a paragraph. You speak clearly
and directly.
```

### The Skeptic (student archetype, Grade 6+)
```
You are the student who questions everything — in a good way.

You push back gently: "Is that always true?" "What about the opposite case?"
"That seems like it would break if..." You help the class think more deeply by
challenging assumptions. You are curious, not combative.

Keep it SHORT. One pointed question or observation. You provoke thought without
taking over the conversation.
```

### The Enthusiast (student archetype)
```
You are the student who connects everything.

You get excited about links between this topic and other things you know.
"Oh! This is like what we learned about..." You sometimes get a bit ahead,
which makes the teacher rein you back in — and that's OK. Your energy is
contagious.

Keep it SHORT. A quick excited connection or observation. Keep the energy up
without going off-track.
```

---

## Part 10 — Implementation checklist for Cursor (Phase 3d)

**Database:**
- [ ] 7 new migrations (classroom_lessons, lesson_agents, lesson_scenes,
      classroom_sessions, classroom_messages, lesson_quiz_attempts,
      whiteboard_snapshots)
- [ ] All models with district global scopes, relationships, casts

**Generation pipeline:**
- [ ] LessonGeneratorService::generateOutline() — Stage 1 LLM call
- [ ] LessonGeneratorService::generateSlideContent() — slide elements + actions
- [ ] LessonGeneratorService::generateQuizContent() — quiz questions
- [ ] LessonGeneratorService::generateInteractiveContent() — scientific model + HTML + post-process
- [ ] InteractiveHtmlProcessor — LaTeX conversion, KaTeX injection, validation
- [ ] Horizon jobs: GenerateLessonOutlineJob, GenerateSceneContentJob (one per scene, parallel)
- [ ] Polling endpoint: GET /teach/lessons/{id}/status

**Orchestration:**
- [ ] DirectorService — single-agent fast path + multi-agent LLM path
- [ ] AgentOrchestrator — system prompt builder + streaming JSON array parser
- [ ] WhiteboardService — apply/get/snapshot operations

**Quiz grading:**
- [ ] QuizGraderService — deterministic MC + LLM short answer

**API routes (teacher):**
- [ ] Full CRUD for lessons and scenes
- [ ] Publish lesson to space
- [ ] Export summary

**API routes (student):**
- [ ] Start classroom session
- [ ] Message endpoint (SSE stream)
- [ ] Whiteboard polling endpoint
- [ ] Quiz submit endpoint (SSE grade response)
- [ ] End session

**Frontend — Teacher:**
- [ ] LessonBuilder page (source input + agent picker)
- [ ] GenerationProgress component (polling display)
- [ ] LessonEditor page (scene review + edit per scene type)
- [ ] Classroom replay in Compass View (action log + whiteboard snapshots)

**Frontend — Student:**
- [ ] ClassroomView page (3-panel layout)
- [ ] AgentPanel (speaking indicators, color-coded)
- [ ] ClassroomChat (multi-agent bubbles with avatars)
- [ ] SlideRenderer (elements + spotlight overlay + laser overlay)
- [ ] WhiteboardCanvas (element array renderer + appearance animations)
- [ ] QuizWidget (MC + short answer + grading feedback)
- [ ] SimulationFrame (sandboxed iframe + done button)
- [ ] "Just talk to Atlas" escape hatch

**Safety:**
- [ ] SafetyFilter runs on all agent output text
- [ ] Agent persona sanitization in LessonAgent model
- [ ] Interactive HTML validation in InteractiveHtmlProcessor
- [ ] Whiteboard coordinate clamping in WhiteboardService
- [ ] Compass View: agent messages visible alongside student messages

---

*This specification was produced by reading and analyzing the OpenMAIC source
code and expressing the behavioral understanding in plain English. No code
was copied. Implementation in PHP/Laravel/React will be original.*

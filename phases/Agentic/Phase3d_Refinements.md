# ATLAAS — Phase 3d Technical Refinements
## Apply these before or alongside Phase3d_MultiAgent_Classroom.md
## These close two architectural gaps identified after the initial build script.

---

## Refinement 1 — Typed Action DTOs

### The problem with the current approach

`AgentOrchestrator` currently passes actions as raw `array` throughout:
```php
// Current — no validation, no type safety
$actions[] = ['type' => 'wb_draw_text', 'x' => $action['x'] ?? 100, ...]
```

If the LLM hallucinates a bad coordinate, a missing `elementId`, or an
unknown action type, the error surfaces as a cryptic runtime failure at
the whiteboard or broadcast layer — not at the point where the bad data
entered the system. Typed DTOs push validation to the boundary, where it's
cheapest to catch and easiest to debug.

### `app/Actions/Classroom/` directory structure

```
app/Actions/Classroom/
├── BaseAction.php
├── FireAndForgetAction.php       (abstract)
├── SynchronousAction.php         (abstract)
├── Spotlight.php
├── Laser.php
├── Speech.php
├── WbOpen.php
├── WbClose.php
├── WbClear.php
├── WbDrawText.php
├── WbDrawShape.php
├── WbDrawChart.php
├── WbDrawLatex.php
├── WbDrawTable.php
├── WbDrawLine.php
├── WbDelete.php
├── Discussion.php
└── ActionFactory.php
```

### `app/Actions/Classroom/BaseAction.php`

```php
<?php
namespace App\Actions\Classroom;

abstract class BaseAction
{
    public readonly string $id;

    public function __construct(
        public readonly string $type,
        array $params = [],
    ) {
        $this->id = 'act_' . \Illuminate\Support\Str::random(8);
        $this->hydrateParams($params);
    }

    /**
     * Hydrate typed properties from the raw params array.
     * Each subclass implements this to pull its own fields.
     */
    abstract protected function hydrateParams(array $params): void;

    /**
     * Validate that all required params are present and valid.
     * Throws \InvalidArgumentException if invalid.
     */
    abstract public function validate(): void;

    /**
     * Whether this action blocks until completion (true)
     * or fires and returns immediately (false).
     */
    abstract public function isSynchronous(): bool;

    /**
     * Serialize to array for SSE broadcast and DB storage.
     */
    public function toArray(): array
    {
        return [
            'id'   => $this->id,
            'type' => $this->type,
        ];
    }

    /**
     * Clamp a coordinate to the whiteboard canvas bounds.
     */
    protected function clampX(int|float $v): int
    {
        return (int) max(0, min($v, 1000));
    }

    protected function clampY(int|float $v): int
    {
        return (int) max(0, min($v, 562));
    }

    protected function clampDim(int|float $v, int $max = 1000): int
    {
        return (int) max(1, min($v, $max));
    }
}
```

### `app/Actions/Classroom/Speech.php`

```php
<?php
namespace App\Actions\Classroom;

class Speech extends BaseAction
{
    public string  $text;
    public ?string $voice;
    public float   $speed;

    public function __construct(array $params = [])
    {
        parent::__construct('speech', $params);
    }

    protected function hydrateParams(array $p): void
    {
        $this->text  = (string)($p['text'] ?? $p['content'] ?? '');
        $this->voice = $p['voice'] ?? null;
        $this->speed = (float)($p['speed'] ?? 1.0);
    }

    public function validate(): void
    {
        if (trim($this->text) === '') {
            throw new \InvalidArgumentException('Speech action requires non-empty text');
        }
        if ($this->speed < 0.5 || $this->speed > 2.0) {
            $this->speed = 1.0; // clamp silently — not a hard error
        }
    }

    public function isSynchronous(): bool { return true; }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'text'  => $this->text,
            'voice' => $this->voice,
            'speed' => $this->speed,
        ]);
    }
}
```

### `app/Actions/Classroom/Spotlight.php`

```php
<?php
namespace App\Actions\Classroom;

class Spotlight extends BaseAction
{
    public string $elementId;
    public float  $dimOpacity;

    public function __construct(array $params = [])
    {
        parent::__construct('spotlight', $params);
    }

    protected function hydrateParams(array $p): void
    {
        $this->elementId  = (string)($p['elementId'] ?? '');
        $this->dimOpacity = (float)($p['dimOpacity'] ?? 0.5);
    }

    public function validate(): void
    {
        if ($this->elementId === '') {
            throw new \InvalidArgumentException('Spotlight requires elementId');
        }
        $this->dimOpacity = max(0.1, min(0.9, $this->dimOpacity));
    }

    public function isSynchronous(): bool { return false; } // fire-and-forget

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'elementId'  => $this->elementId,
            'dimOpacity' => $this->dimOpacity,
        ]);
    }
}
```

### `app/Actions/Classroom/Laser.php`

```php
<?php
namespace App\Actions\Classroom;

class Laser extends BaseAction
{
    public string $elementId;
    public string $color;

    public function __construct(array $params = [])
    {
        parent::__construct('laser', $params);
    }

    protected function hydrateParams(array $p): void
    {
        $this->elementId = (string)($p['elementId'] ?? '');
        $this->color     = (string)($p['color'] ?? '#ff0000');
    }

    public function validate(): void
    {
        if ($this->elementId === '') {
            throw new \InvalidArgumentException('Laser requires elementId');
        }
    }

    public function isSynchronous(): bool { return false; }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'elementId' => $this->elementId,
            'color'     => $this->color,
        ]);
    }
}
```

### `app/Actions/Classroom/WbDrawText.php`

```php
<?php
namespace App\Actions\Classroom;

class WbDrawText extends BaseAction
{
    public string  $content;
    public int     $x;
    public int     $y;
    public int     $width;
    public int     $height;
    public int     $fontSize;
    public string  $color;
    public ?string $elementId;

    public function __construct(array $params = [])
    {
        parent::__construct('wb_draw_text', $params);
    }

    protected function hydrateParams(array $p): void
    {
        $this->content   = (string)($p['content'] ?? '');
        $this->x         = $this->clampX($p['x'] ?? 100);
        $this->y         = $this->clampY($p['y'] ?? 100);
        $this->width     = $this->clampDim($p['width']  ?? 400, 1000);
        $this->height    = $this->clampDim($p['height'] ?? 100, 562);
        $this->fontSize  = (int) max(10, min($p['fontSize'] ?? 18, 72));
        $this->color     = (string)($p['color'] ?? '#333333');
        $this->elementId = $p['elementId'] ?? null;

        // Ensure element doesn't overflow canvas
        if ($this->x + $this->width > 1000)  $this->width  = 1000 - $this->x;
        if ($this->y + $this->height > 562)   $this->height = 562  - $this->y;
    }

    public function validate(): void
    {
        if (trim($this->content) === '') {
            throw new \InvalidArgumentException('WbDrawText requires non-empty content');
        }
    }

    public function isSynchronous(): bool { return true; }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'content'   => $this->content,
            'x'         => $this->x,
            'y'         => $this->y,
            'width'     => $this->width,
            'height'    => $this->height,
            'fontSize'  => $this->fontSize,
            'color'     => $this->color,
            'elementId' => $this->elementId,
        ]);
    }
}
```

### `app/Actions/Classroom/WbDrawShape.php`

```php
<?php
namespace App\Actions\Classroom;

class WbDrawShape extends BaseAction
{
    public const ALLOWED_SHAPES = ['rectangle', 'circle', 'triangle'];

    public string  $shape;
    public int     $x;
    public int     $y;
    public int     $width;
    public int     $height;
    public string  $fillColor;
    public ?string $elementId;

    public function __construct(array $params = [])
    {
        parent::__construct('wb_draw_shape', $params);
    }

    protected function hydrateParams(array $p): void
    {
        $this->shape     = in_array($p['shape'] ?? '', self::ALLOWED_SHAPES)
                           ? $p['shape'] : 'rectangle';
        $this->x         = $this->clampX($p['x'] ?? 200);
        $this->y         = $this->clampY($p['y'] ?? 150);
        $this->width     = $this->clampDim($p['width']  ?? 200, 1000);
        $this->height    = $this->clampDim($p['height'] ?? 100, 562);
        $this->fillColor = (string)($p['fillColor'] ?? '#5b9bd5');
        $this->elementId = $p['elementId'] ?? null;

        if ($this->x + $this->width  > 1000) $this->width  = 1000 - $this->x;
        if ($this->y + $this->height > 562)  $this->height = 562  - $this->y;
    }

    public function validate(): void {} // shape is already validated in hydrate

    public function isSynchronous(): bool { return true; }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'shape'     => $this->shape,
            'x'         => $this->x,
            'y'         => $this->y,
            'width'     => $this->width,
            'height'    => $this->height,
            'fillColor' => $this->fillColor,
            'elementId' => $this->elementId,
        ]);
    }
}
```

### `app/Actions/Classroom/WbDrawLatex.php`

```php
<?php
namespace App\Actions\Classroom;

class WbDrawLatex extends BaseAction
{
    public string  $latex;
    public int     $x;
    public int     $y;
    public int     $width;
    public int     $height;
    public string  $color;
    public string  $align;
    public ?string $elementId;

    public function __construct(array $params = [])
    {
        parent::__construct('wb_draw_latex', $params);
    }

    protected function hydrateParams(array $p): void
    {
        $this->latex     = (string)($p['latex'] ?? '');
        $this->x         = $this->clampX($p['x'] ?? 100);
        $this->y         = $this->clampY($p['y'] ?? 100);
        $this->width     = $this->clampDim($p['width']  ?? 400, 1000);
        $this->height    = $this->clampDim($p['height'] ?? 80, 300);
        $this->color     = (string)($p['color'] ?? '#000000');
        $this->align     = in_array($p['align'] ?? '', ['left','center','right'])
                           ? $p['align'] : 'center';
        $this->elementId = $p['elementId'] ?? null;
    }

    public function validate(): void
    {
        if (trim($this->latex) === '') {
            throw new \InvalidArgumentException('WbDrawLatex requires non-empty latex');
        }
        // Basic sanity: reject if it's clearly not LaTeX (e.g. someone passed HTML)
        if (str_contains($this->latex, '<script') || str_contains($this->latex, '<img')) {
            throw new \InvalidArgumentException('WbDrawLatex contains disallowed HTML');
        }
    }

    public function isSynchronous(): bool { return true; }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'latex'     => $this->latex,
            'x'         => $this->x,
            'y'         => $this->y,
            'width'     => $this->width,
            'height'    => $this->height,
            'color'     => $this->color,
            'align'     => $this->align,
            'elementId' => $this->elementId,
        ]);
    }
}
```

### `app/Actions/Classroom/WbDrawChart.php`

```php
<?php
namespace App\Actions\Classroom;

class WbDrawChart extends BaseAction
{
    public const ALLOWED_TYPES = ['bar','column','line','pie','ring','area','radar','scatter'];

    public string  $chartType;
    public int     $x;
    public int     $y;
    public int     $width;
    public int     $height;
    public array   $data;         // {labels:[], legends:[], series:[[]]}
    public array   $themeColors;
    public ?string $elementId;

    public function __construct(array $params = [])
    {
        parent::__construct('wb_draw_chart', $params);
    }

    protected function hydrateParams(array $p): void
    {
        $this->chartType   = in_array($p['chartType'] ?? '', self::ALLOWED_TYPES)
                             ? $p['chartType'] : 'bar';
        $this->x           = $this->clampX($p['x'] ?? 100);
        $this->y           = $this->clampY($p['y'] ?? 100);
        $this->width       = $this->clampDim($p['width']  ?? 400, 1000);
        $this->height      = $this->clampDim($p['height'] ?? 250, 562);
        $this->data        = $p['data'] ?? ['labels' => [], 'legends' => [], 'series' => [[]]];
        $this->themeColors = $p['themeColors'] ?? ['#5b9bd5', '#ed7d31', '#a9d18e'];
        $this->elementId   = $p['elementId'] ?? null;
    }

    public function validate(): void
    {
        if (empty($this->data['labels'])) {
            throw new \InvalidArgumentException('WbDrawChart requires at least one label');
        }
    }

    public function isSynchronous(): bool { return true; }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'chartType'   => $this->chartType,
            'x'           => $this->x,
            'y'           => $this->y,
            'width'       => $this->width,
            'height'      => $this->height,
            'data'        => $this->data,
            'themeColors' => $this->themeColors,
            'elementId'   => $this->elementId,
        ]);
    }
}
```

### `app/Actions/Classroom/WbDrawTable.php`

```php
<?php
namespace App\Actions\Classroom;

class WbDrawTable extends BaseAction
{
    public int     $x;
    public int     $y;
    public int     $width;
    public int     $height;
    public array   $data;    // string[][] — first row is header
    public ?string $elementId;

    public function __construct(array $params = [])
    {
        parent::__construct('wb_draw_table', $params);
    }

    protected function hydrateParams(array $p): void
    {
        $this->x         = $this->clampX($p['x'] ?? 100);
        $this->y         = $this->clampY($p['y'] ?? 100);
        $this->width     = $this->clampDim($p['width']  ?? 500, 1000);
        $this->height    = $this->clampDim($p['height'] ?? 160, 562);
        $this->data      = array_map(
            fn($row) => array_map('strval', (array)$row),
            (array)($p['data'] ?? [['Header']])
        );
        $this->elementId = $p['elementId'] ?? null;
    }

    public function validate(): void
    {
        if (empty($this->data)) {
            throw new \InvalidArgumentException('WbDrawTable requires at least one row');
        }
    }

    public function isSynchronous(): bool { return true; }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'x'         => $this->x,
            'y'         => $this->y,
            'width'     => $this->width,
            'height'    => $this->height,
            'data'      => $this->data,
            'elementId' => $this->elementId,
        ]);
    }
}
```

### `app/Actions/Classroom/WbDrawLine.php`

```php
<?php
namespace App\Actions\Classroom;

class WbDrawLine extends BaseAction
{
    public int     $startX;
    public int     $startY;
    public int     $endX;
    public int     $endY;
    public string  $color;
    public int     $width;
    public string  $style;
    public array   $points;  // ['', 'arrow'] etc.
    public ?string $elementId;

    public function __construct(array $params = [])
    {
        parent::__construct('wb_draw_line', $params);
    }

    protected function hydrateParams(array $p): void
    {
        $this->startX    = $this->clampX($p['startX'] ?? 0);
        $this->startY    = $this->clampY($p['startY'] ?? 0);
        $this->endX      = $this->clampX($p['endX']   ?? 100);
        $this->endY      = $this->clampY($p['endY']   ?? 100);
        $this->color     = (string)($p['color'] ?? '#333333');
        $this->width     = (int) max(1, min($p['width'] ?? 2, 10));
        $this->style     = in_array($p['style'] ?? '', ['solid','dashed']) ? $p['style'] : 'solid';
        $this->points    = $p['points'] ?? ['', ''];
        $this->elementId = $p['elementId'] ?? null;
    }

    public function validate(): void
    {
        // A zero-length line is useless but not harmful — allow it
    }

    public function isSynchronous(): bool { return true; }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'startX'    => $this->startX,
            'startY'    => $this->startY,
            'endX'      => $this->endX,
            'endY'      => $this->endY,
            'color'     => $this->color,
            'width'     => $this->width,
            'style'     => $this->style,
            'points'    => $this->points,
            'elementId' => $this->elementId,
        ]);
    }
}
```

### Simple action classes (no meaningful params to validate)

```php
<?php
// app/Actions/Classroom/WbOpen.php
namespace App\Actions\Classroom;
class WbOpen extends BaseAction {
    public function __construct(array $params = []) { parent::__construct('wb_open', $params); }
    protected function hydrateParams(array $p): void {}
    public function validate(): void {}
    public function isSynchronous(): bool { return true; }
}

// app/Actions/Classroom/WbClose.php
namespace App\Actions\Classroom;
class WbClose extends BaseAction {
    public function __construct(array $params = []) { parent::__construct('wb_close', $params); }
    protected function hydrateParams(array $p): void {}
    public function validate(): void {}
    public function isSynchronous(): bool { return true; }
}

// app/Actions/Classroom/WbClear.php
namespace App\Actions\Classroom;
class WbClear extends BaseAction {
    public function __construct(array $params = []) { parent::__construct('wb_clear', $params); }
    protected function hydrateParams(array $p): void {}
    public function validate(): void {}
    public function isSynchronous(): bool { return true; }
}

// app/Actions/Classroom/WbDelete.php
namespace App\Actions\Classroom;
class WbDelete extends BaseAction {
    public string $elementId;
    public function __construct(array $params = []) { parent::__construct('wb_delete', $params); }
    protected function hydrateParams(array $p): void {
        $this->elementId = (string)($p['elementId'] ?? '');
    }
    public function validate(): void {
        if ($this->elementId === '') throw new \InvalidArgumentException('WbDelete requires elementId');
    }
    public function isSynchronous(): bool { return true; }
    public function toArray(): array {
        return array_merge(parent::toArray(), ['elementId' => $this->elementId]);
    }
}

// app/Actions/Classroom/Discussion.php
namespace App\Actions\Classroom;
class Discussion extends BaseAction {
    public string  $topic;
    public ?string $prompt;
    public ?string $agentId;
    public function __construct(array $params = []) { parent::__construct('discussion', $params); }
    protected function hydrateParams(array $p): void {
        $this->topic   = (string)($p['topic'] ?? '');
        $this->prompt  = $p['prompt']  ?? null;
        $this->agentId = $p['agentId'] ?? null;
    }
    public function validate(): void {
        if ($this->topic === '') throw new \InvalidArgumentException('Discussion requires topic');
    }
    public function isSynchronous(): bool { return true; }
    public function toArray(): array {
        return array_merge(parent::toArray(), [
            'topic'   => $this->topic,
            'prompt'  => $this->prompt,
            'agentId' => $this->agentId,
        ]);
    }
}
```

### `app/Actions/Classroom/ActionFactory.php`

The single entry point for converting raw LLM output into validated typed objects.

```php
<?php
namespace App\Actions\Classroom;

class ActionFactory
{
    private const MAP = [
        'speech'        => Speech::class,
        'spotlight'     => Spotlight::class,
        'laser'         => Laser::class,
        'wb_open'       => WbOpen::class,
        'wb_close'      => WbClose::class,
        'wb_clear'      => WbClear::class,
        'wb_delete'     => WbDelete::class,
        'wb_draw_text'  => WbDrawText::class,
        'wb_draw_shape' => WbDrawShape::class,
        'wb_draw_chart' => WbDrawChart::class,
        'wb_draw_latex' => WbDrawLatex::class,
        'wb_draw_table' => WbDrawTable::class,
        'wb_draw_line'  => WbDrawLine::class,
        'discussion'    => Discussion::class,
    ];

    /**
     * Build and validate a typed Action from raw LLM output.
     * Returns null if the action type is unknown or validation fails.
     */
    public static function make(string $type, array $params): ?BaseAction
    {
        $class = self::MAP[$type] ?? null;
        if (!$class) return null;

        try {
            $action = new $class($params);
            $action->validate();
            return $action;
        } catch (\InvalidArgumentException $e) {
            \Illuminate\Support\Facades\Log::warning(
                "[ActionFactory] Invalid action '{$type}': " . $e->getMessage(),
                ['params' => $params]
            );
            return null; // skip invalid actions rather than crashing the stream
        }
    }

    /**
     * Build a Speech action directly from text content.
     * Used when the LLM emits a type:"text" item.
     */
    public static function speech(string $text, ?string $voice = null): Speech
    {
        $action = new Speech(['text' => $text, 'voice' => $voice]);
        $action->validate();
        return $action;
    }

    /**
     * Return all known action type strings.
     */
    public static function knownTypes(): array
    {
        return array_keys(self::MAP);
    }
}
```

### Update `AgentOrchestrator` to use typed actions

Replace the raw array handling in `generateTurn()` with ActionFactory calls:

```php
// In AgentOrchestrator::generateTurn(), replace the item emission block:

if ($item['type'] === 'text') {
    $text = $item['content'] ?? '';
    $newText = mb_substr($text, $partialTextLen);
    if ($newText !== '') {
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

    // Build typed, validated action — skips unknown/invalid actions cleanly
    $typedAction = \App\Actions\Classroom\ActionFactory::make($name, $params);
    if (!$typedAction) continue; // skip and log

    // Apply whiteboard side-effects server-side for wb_* actions
    if (str_starts_with($name, 'wb_')) {
        // Pass agent ID for ledger tracking
        $actionArray = array_merge($typedAction->toArray(), ['_agent_id' => $agent->id]);
        $this->whiteboard->applyAction($session, $actionArray);
        $wbActions[] = [
            'action_name' => $name,
            'agent_id'    => $agent->id,
            'agent_name'  => $agent->display_name,
            'params'      => $typedAction->toArray(),
        ];
    }

    yield ['type' => 'action', 'data' => array_merge(
        $typedAction->toArray(),
        ['agentId' => $agent->id]
    )];
    $actionCount++;
}
```

### Update `WhiteboardService::applyAction()` to accept typed actions

The `WhiteboardService::buildElement()` method already does its own
coordinate clamping. With typed DTOs, that's now double validation —
which is fine. Update the method signature to accept either a raw array
or a typed action:

```php
public function applyAction(ClassroomSession $session, array $action): void
{
    // If a typed action was serialized via toArray(), the type field is present
    // This method continues to accept arrays so it works with both code paths
    $type = $action['type'] ?? '';
    // ... existing match() logic unchanged ...
}
```

The `WhiteboardService` doesn't need to change — the typed DTO validates
before `applyAction` is called, so by the time data arrives here it's
already been checked.

---

## Refinement 2 — Director token budget and anti-loop guardrail

### The two problems

**Problem A — Per-request vs. cumulative turn limit**

The current code caps at `min(count($agents), 3)` turns *per request*.
But if a student sends 4 messages and never engages (presses Enter
repeatedly, or goes idle), agents can fire 12 times in a row without
ever being asked for student input. The Director's `agents_spoken` list
resets on each new student message via `startNewRound()`, so the per-round
limit is correct — but there's no cross-round "have we talked enough
without the student contributing?" check.

**Problem B — Director can route to the same agent twice in a session**

If a session has only one student agent and the teacher has already spoken,
the Director might loop: teacher → student → teacher → student → teacher...
without ever forcing a USER cue. The current `agents_spoken` ledger only
covers the *current round*, not the session history.

### The fix: cumulative engagement tracker

Add two fields to `director_state` in `ClassroomSession`:

```php
// director_state shape (updated):
[
    'turn_count'          => 0,    // total agent turns ever in this session
    'rounds_without_input'=> 0,    // consecutive agent rounds since last student message
    'agents_spoken'       => [...], // current round only (resets per message)
    'whiteboard_ledger'   => [...],
]
```

Update `DirectorService`:

```php
<?php
namespace App\Services\Classroom;

use App\Models\ClassroomSession;
use App\Models\LessonAgent;
use App\Services\AI\LLMService;
use Illuminate\Support\Collection;

class DirectorService
{
    // Hard limits — cannot be overridden by any configuration
    const MAX_TURNS_PER_ROUND          = 3;   // max agent turns per student message
    const MAX_ROUNDS_WITHOUT_INPUT     = 2;   // consecutive agent-only rounds before forcing USER
    const MAX_TOTAL_TURNS_PER_SESSION  = 60;  // safety valve: session-wide agent turn cap

    public function __construct(private LLMService $llm) {}

    /**
     * Decide which agent speaks next.
     * Returns: agent ID string, 'USER' (cue student), or null (END session turn).
     */
    public function nextAgentId(ClassroomSession $session, array $agents): string|null
    {
        $state       = $session->director_state ?? [];
        $totalTurns  = $state['turn_count'] ?? 0;
        $roundsEmpty = $state['rounds_without_input'] ?? 0;
        $spokenCount = count($state['agents_spoken'] ?? []);

        // ── Hard stop 1: session-wide turn cap ────────────────────────────
        if ($totalTurns >= self::MAX_TOTAL_TURNS_PER_SESSION) {
            \Log::info("[Director] Session turn cap reached ({$totalTurns}), ending");
            return null;
        }

        // ── Hard stop 2: agents talked too many times without student input
        if ($roundsEmpty >= self::MAX_ROUNDS_WITHOUT_INPUT) {
            \Log::info("[Director] {$roundsEmpty} rounds without student input — forcing USER cue");
            return 'USER';
        }

        // ── Hard stop 3: per-round turn limit ─────────────────────────────
        if ($spokenCount >= self::MAX_TURNS_PER_ROUND) {
            \Log::info("[Director] Round turn limit ({$spokenCount}) reached — forcing USER cue");
            return 'USER';
        }

        $activeAgents = collect($agents)->where('is_active', true)->values();

        if ($activeAgents->count() <= 1) {
            return $this->singleAgentDecision($session, $activeAgents->first());
        }

        return $this->multiAgentDecision($session, $activeAgents->all(), $state);
    }

    private function singleAgentDecision(
        ClassroomSession $session,
        ?LessonAgent $agent,
    ): ?string {
        if (!$agent) return null;
        $state = $session->director_state ?? [];
        $spokenThisRound = count($state['agents_spoken'] ?? []);

        // First turn of the round: dispatch the agent
        if ($spokenThisRound === 0) return $agent->id;

        // Agent already responded this round: cue the student
        return 'USER';
    }

    private function multiAgentDecision(
        ClassroomSession $session,
        array $agents,
        array $state,
    ): ?string {
        // Fast path: first turn, dispatch teacher
        if (($state['turn_count'] ?? 0) === 0) {
            $teacher = collect($agents)->firstWhere('role', 'teacher');
            return $teacher?->id;
        }

        $prompt   = $this->buildDirectorPrompt($session, $agents, $state);
        $response = $this->llm->complete(
            'You are the director of a multi-agent K-12 classroom. Output only a JSON object.',
            $prompt,
            maxTokens: 120,
        );

        return $this->parseDirectorDecision($response, $agents);
    }

    private function buildDirectorPrompt(
        ClassroomSession $session,
        array $agents,
        array $state,
    ): string {
        $agentList = collect($agents)
            ->map(fn($a) => "- id:\"{$a->id}\", name:\"{$a->display_name}\", role:{$a->role}, priority:{$a->priority}")
            ->implode("\n");

        $spoken = $state['agents_spoken'] ?? [];
        $spokenList = empty($spoken) ? 'None yet.' : collect($spoken)
            ->map(fn($s) => "- {$s['name']} ({$s['agent_id']}): \"{$s['preview']}\" [{$s['action_count']} actions]")
            ->implode("\n");

        $wbLedger = $state['whiteboard_ledger'] ?? [];
        $wbCount  = count(array_filter($wbLedger, fn($r) => str_starts_with($r['action_name'] ?? '', 'wb_draw_')));
        $wbNote   = $wbCount > 5
            ? "\n⚠ Whiteboard has {$wbCount} elements — prefer agents that organize rather than add more."
            : ($wbCount > 0 ? "\nWhiteboard elements: {$wbCount}" : '');

        $roundsEmpty = $state['rounds_without_input'] ?? 0;
        $totalTurns  = $state['turn_count'] ?? 0;
        $spokenCount = count($spoken);

        // Urgency hint: tell Director if student engagement is overdue
        $engagementHint = match(true) {
            $spokenCount >= 2  => "\n⚠ IMPORTANT: {$spokenCount} agents have spoken this round. You MUST output USER unless there is a very specific reason for one more agent.",
            $roundsEmpty >= 1  => "\n⚠ The student has not responded in {$roundsEmpty} round(s). Strongly consider cueing USER.",
            default            => '',
        };

        return <<<PROMPT
# Available agents
{$agentList}

# Already spoke this round
{$spokenList}{$wbNote}{$engagementHint}

# Rules
1. Teacher speaks first if no one has spoken yet.
2. After teacher, one student agent may add a reaction (question, observation).
3. NEVER repeat an agent who already spoke this round.
4. Prefer brevity — 1-2 agents per round maximum.
5. Output USER when a direct question is posed to the student, or when discussion is complete.
6. Output END when the topic is fully covered and no agent would add value.
7. ROLE DIVERSITY: Do not dispatch two teacher-role agents in a row.
8. Current turn: {$totalTurns}. Rounds without student input: {$roundsEmpty}.

# Output (JSON only — nothing else)
{"next_agent":"<agent_id>"} or {"next_agent":"USER"} or {"next_agent":"END"}
PROMPT;
    }

    private function parseDirectorDecision(string $response, array $agents): ?string
    {
        if (preg_match('/\{[^}]*"next_agent"\s*:\s*"([^"]+)"[^}]*\}/s', $response, $m)) {
            $val = trim($m[1]);
            if ($val === 'END') return null;
            if ($val === 'USER') return 'USER';

            // Validate: must be a real agent ID
            $validIds = collect($agents)->pluck('id')->all();
            if (in_array($val, $validIds)) return $val;

            // LLM returned an unknown ID — log and force USER
            \Log::warning("[Director] Unknown agent ID '{$val}' — falling back to USER");
            return 'USER';
        }

        // Unparseable — default to USER (never silently continue)
        \Log::warning("[Director] Could not parse decision from: " . mb_substr($response, 0, 200));
        return 'USER';
    }

    /**
     * Record an agent's completed turn.
     * Called by AgentOrchestrator after each agent finishes.
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

        $state['turn_count']   = ($state['turn_count'] ?? 0) + 1;
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
     * Reset per-round tracking when student sends a new message.
     * Increments the "rounds without input" counter BEFORE resetting —
     * if the previous round had agent activity with no student reply,
     * that counts toward the engagement threshold.
     */
    public function startNewRound(ClassroomSession $session, bool $studentReplied): void
    {
        $state = $session->director_state ?? [];

        // Count whether agents spoke last round without student reply
        $agentSpokeLast = count($state['agents_spoken'] ?? []) > 0;

        if ($agentSpokeLast && !$studentReplied) {
            $state['rounds_without_input'] = ($state['rounds_without_input'] ?? 0) + 1;
        } else {
            // Student replied — reset the counter
            $state['rounds_without_input'] = 0;
        }

        // Always reset per-round agent list
        $state['agents_spoken'] = [];

        $session->update(['director_state' => $state]);
    }
}
```

### Update `ClassroomController::message()` to track student reply

Change the `startNewRound()` call to pass whether the student replied:

```php
// Replace the existing startNewRound() call:
$this->director->startNewRound($session, studentReplied: true);
// (true because the student just sent a message — this is their reply)
```

Add a mechanism for tracking "agent-only" rounds. When the Director returns
`USER` and the student doesn't immediately reply (they went idle), the next
time they send a message we can check if a round happened without engagement.
This is handled by the `rounds_without_input` counter in `startNewRound()`.

### Update the SSE loop in `ClassroomController::message()`

Replace the agent dispatch loop to respect the new Director constants:

```php
return response()->stream(function () use ($session, $agents, $studentMessage) {

    $turnsThisRequest = 0;

    while ($turnsThisRequest < DirectorService::MAX_TURNS_PER_ROUND) {

        $session->refresh();

        $nextId = $this->director->nextAgentId($session, $agents->all());

        if ($nextId === null) {
            // Director said END
            break;
        }

        if ($nextId === 'USER') {
            $this->sendSseEvent(['type' => 'cue_user', 'data' => [
                'prompt' => $this->generateCuePrompt($session),
            ]]);
            break;
        }

        $agent = $agents->firstWhere('id', $nextId);
        if (!$agent) {
            \Log::warning("[Classroom] Director returned unknown agent ID: {$nextId}");
            break;
        }

        $this->sendSseEvent(['type' => 'thinking', 'data' => [
            'stage'   => 'agent_loading',
            'agentId' => $agent->id,
        ]]);

        $allText    = '';
        $allActions = [];

        foreach ($this->orchestrator->generateTurn($session, $agent, $studentMessage) as $event) {
            $this->sendSseEvent($event);
            if ($event['type'] === 'text_delta') $allText    .= $event['data']['content'];
            if ($event['type'] === 'action')     $allActions[] = $event['data'];
        }

        \App\Models\ClassroomMessage::create([
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


/**
 * Generate a contextual cue prompt based on current scene.
 */
private function generateCuePrompt(ClassroomSession $session): string
{
    $scene = $session->currentScene;

    if ($scene?->scene_type === 'quiz') {
        return 'Take your time with the question above.';
    }
    if ($scene?->scene_type === 'discussion') {
        return 'What do you think? Share your thoughts.';
    }

    $state   = $session->director_state ?? [];
    $spoken  = $state['agents_spoken'] ?? [];
    $lastAgent = end($spoken);

    if ($lastAgent && str_contains($lastAgent['preview'] ?? '', '?')) {
        return 'Take a moment to think about that question.';
    }

    return 'What questions do you have so far?';
}
```

---

## Refinement 3 — Action validation in `WhiteboardService`

With typed DTOs now handling validation upstream, update `WhiteboardService`
to accept typed actions directly in addition to raw arrays. This makes the
service usable from both the streaming path (typed) and any future batch
operations (array):

```php
// In WhiteboardService::applyAction(), add typed action support:

public function applyAction(ClassroomSession $session, array|\App\Actions\Classroom\BaseAction $action): void
{
    // Normalize: if a typed action, convert to array first
    if ($action instanceof \App\Actions\Classroom\BaseAction) {
        $actionArray = $action->toArray();
    } else {
        $actionArray = $action;
    }

    $type = $actionArray['type'] ?? '';

    match($type) {
        'wb_open'       => $this->open($session),
        'wb_close'      => $this->close($session),
        'wb_clear'      => $this->clear($session),
        'wb_delete'     => $this->delete($session, $actionArray['elementId'] ?? ''),
        default         => $this->addElement($session, $actionArray),
    };
}
```

---

## Summary of what these refinements fix

| Issue | Before | After |
|-------|--------|-------|
| Bad LLM action params | Silent runtime errors at broadcast | `ActionFactory::make()` catches and logs, returns null — stream continues |
| Unknown action type | Passed to whiteboard as raw array | `ActionFactory` returns null, action skipped |
| Missing `elementId` on spotlight | PHP warning, broken SSE | `Spotlight::validate()` throws, caught by factory, action skipped |
| Agents looping without student input | Possible with 3+ messages and passive student | `rounds_without_input` counter forces USER after 2 agent-only rounds |
| Director returning unknown agent ID | Could cause infinite loop or null error | `parseDirectorDecision()` validates against real agent IDs, falls back to USER |
| Session-wide agent runaway | No cap existed | `MAX_TOTAL_TURNS_PER_SESSION = 60` hard stops the session |
| LaTeX action with HTML injection | Passed directly to whiteboard | `WbDrawLatex::validate()` blocks `<script>` and `<img>` |

---

## Acceptance additions for Phase 3d checklist

- [ ] `ActionFactory::make('unknown_type', [])` returns null without throwing
- [ ] `ActionFactory::make('spotlight', [])` (missing elementId) returns null and logs warning
- [ ] `ActionFactory::make('wb_draw_text', ['content'=>'hello','x'=>-50,'y'=>700])` clamps to valid coords (x=0, y=562)
- [ ] `ActionFactory::make('wb_draw_latex', ['latex'=>'<script>alert(1)</script>'])` returns null
- [ ] Session with 2 agents: student sends message → teacher responds → student agent responds → Director returns USER (not a third agent)
- [ ] Student sends 3 empty/passive messages → after 2 agent-only rounds, Director forces USER cue
- [ ] `DirectorService::MAX_TOTAL_TURNS_PER_SESSION` respected: session with 60 agent turns stops generating new turns
- [ ] Director returning an agent ID not in the session's agent list falls back to USER
- [ ] `WhiteboardService::applyAction()` accepts both `BaseAction` instances and raw arrays

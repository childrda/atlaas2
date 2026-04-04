<?php

namespace App\Services\Classroom;

use App\Actions\Classroom\BaseAction;
use App\Events\ClassroomWhiteboardUpdated;
use App\Models\ClassroomSession;
use App\Models\WhiteboardSnapshot;
use Illuminate\Support\Str;

class WhiteboardService
{
    public const CANVAS_WIDTH = 1000;

    public const CANVAS_HEIGHT = 562;

    /**
     * @param  array<string, mixed>|BaseAction  $action
     */
    public function applyAction(ClassroomSession $session, array|BaseAction $action): void
    {
        if ($action instanceof BaseAction) {
            $actionArray = $action->toArray();
        } else {
            $actionArray = $action;
        }

        $type = $actionArray['type'] ?? '';

        match ($type) {
            'wb_open' => $this->open($session),
            'wb_close' => $this->close($session),
            'wb_clear' => $this->clear($session),
            'wb_delete' => $this->delete($session, (string) ($actionArray['elementId'] ?? '')),
            default => $this->addElement($session, $actionArray),
        };
    }

    private function open(ClassroomSession $session): void
    {
        $session->update(['whiteboard_open' => true]);
        $this->broadcastWhiteboard($session);
    }

    private function close(ClassroomSession $session): void
    {
        $session->update(['whiteboard_open' => false]);
        $this->broadcastWhiteboard($session);
    }

    private function clear(ClassroomSession $session): void
    {
        $session->update(['whiteboard_elements' => [], 'whiteboard_open' => true]);
        $this->broadcastWhiteboard($session);
    }

    private function delete(ClassroomSession $session, string $elementId): void
    {
        $elements = $session->whiteboard_elements ?? [];
        $elements = array_values(array_filter($elements, fn ($el) => ($el['id'] ?? '') !== $elementId));
        $session->update(['whiteboard_elements' => $elements]);
        $this->broadcastWhiteboard($session);
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function addElement(ClassroomSession $session, array $action): void
    {
        $type = $action['type'] ?? '';
        if (! str_starts_with($type, 'wb_draw_')) {
            return;
        }

        $element = $this->buildElement($action);
        if (! $element) {
            return;
        }

        $elements = $session->whiteboard_elements ?? [];
        $elements[] = $element;
        $session->update(['whiteboard_elements' => $elements, 'whiteboard_open' => true]);
        $this->broadcastWhiteboard($session);
    }

    private function broadcastWhiteboard(ClassroomSession $session): void
    {
        $session->refresh();
        ClassroomWhiteboardUpdated::dispatch($session);
    }

    /**
     * @param  array<string, mixed>  $action
     * @return ?array<string, mixed>
     */
    private function buildElement(array $action): ?array
    {
        $type = $action['type'] ?? '';
        $id = $action['elementId'] ?? ('wb_'.Str::random(8));

        $base = [
            'id' => $id,
            'added_by' => $action['_agent_id'] ?? 'unknown',
            'added_at' => now()->timestamp,
        ];

        return match ($type) {
            'wb_draw_text' => array_merge($base, [
                'type' => 'text',
                'left' => $this->clampX($action['x'] ?? 100),
                'top' => $this->clampY($action['y'] ?? 100),
                'width' => min((int) ($action['width'] ?? 400), self::CANVAS_WIDTH),
                'height' => min((int) ($action['height'] ?? 100), self::CANVAS_HEIGHT),
                'content' => '<p style="font-size:'.($action['fontSize'] ?? 18).'px">'.e($action['content'] ?? '').'</p>',
                'color' => $action['color'] ?? '#333333',
            ]),
            'wb_draw_shape' => array_merge($base, [
                'type' => 'shape',
                'left' => $this->clampX($action['x'] ?? 200),
                'top' => $this->clampY($action['y'] ?? 150),
                'width' => min((int) ($action['width'] ?? 200), self::CANVAS_WIDTH),
                'height' => min((int) ($action['height'] ?? 100), self::CANVAS_HEIGHT),
                'shape' => in_array($action['shape'] ?? '', ['rectangle', 'circle', 'triangle'], true)
                    ? $action['shape'] : 'rectangle',
                'fill_color' => $action['fillColor'] ?? '#5b9bd5',
            ]),
            'wb_draw_latex' => array_merge($base, [
                'type' => 'latex',
                'left' => $this->clampX($action['x'] ?? 100),
                'top' => $this->clampY($action['y'] ?? 100),
                'width' => min((int) ($action['width'] ?? 400), self::CANVAS_WIDTH),
                'height' => min((int) ($action['height'] ?? 80), self::CANVAS_HEIGHT),
                'latex' => $action['latex'] ?? '',
                'color' => $action['color'] ?? '#000000',
            ]),
            'wb_draw_chart' => array_merge($base, [
                'type' => 'chart',
                'left' => $this->clampX($action['x'] ?? 100),
                'top' => $this->clampY($action['y'] ?? 100),
                'width' => min((int) ($action['width'] ?? 400), self::CANVAS_WIDTH),
                'height' => min((int) ($action['height'] ?? 250), self::CANVAS_HEIGHT),
                'chart_type' => $action['chartType'] ?? 'bar',
                'data' => $action['data'] ?? [],
            ]),
            'wb_draw_table' => array_merge($base, [
                'type' => 'table',
                'left' => $this->clampX($action['x'] ?? 100),
                'top' => $this->clampY($action['y'] ?? 100),
                'width' => min((int) ($action['width'] ?? 500), self::CANVAS_WIDTH),
                'height' => min((int) ($action['height'] ?? 160), self::CANVAS_HEIGHT),
                'data' => $action['data'] ?? [['Header']],
            ]),
            'wb_draw_line' => array_merge($base, [
                'type' => 'line',
                'start_x' => $this->clampX($action['startX'] ?? 0),
                'start_y' => $this->clampY($action['startY'] ?? 0),
                'end_x' => $this->clampX($action['endX'] ?? 100),
                'end_y' => $this->clampY($action['endY'] ?? 100),
                'color' => $action['color'] ?? '#333333',
                'width' => min((int) ($action['width'] ?? 2), 10),
                'style' => in_array($action['style'] ?? '', ['solid', 'dashed'], true) ? $action['style'] : 'solid',
                'points' => $action['points'] ?? ['', ''],
            ]),
            default => null,
        };
    }

    /**
     * @return array{elements: array, open: bool}
     */
    public function getState(ClassroomSession $session): array
    {
        return [
            'elements' => $session->whiteboard_elements ?? [],
            'open' => (bool) $session->whiteboard_open,
        ];
    }

    public function snapshot(ClassroomSession $session, string $sceneId): void
    {
        if ($sceneId === '') {
            return;
        }

        WhiteboardSnapshot::create([
            'session_id' => $session->id,
            'scene_id' => $sceneId,
            'elements' => $session->whiteboard_elements ?? [],
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

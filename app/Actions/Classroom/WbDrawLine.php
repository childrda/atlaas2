<?php

namespace App\Actions\Classroom;

class WbDrawLine extends BaseAction
{
    public int $startX;

    public int $startY;

    public int $endX;

    public int $endY;

    public string $color;

    public int $width;

    public string $style;

    /** @var list<string> */
    public array $points;

    public ?string $elementId;

    public function __construct(array $params = [])
    {
        parent::__construct('wb_draw_line', $params);
    }

    protected function hydrateParams(array $p): void
    {
        $this->startX = $this->clampX($p['startX'] ?? 0);
        $this->startY = $this->clampY($p['startY'] ?? 0);
        $this->endX = $this->clampX($p['endX'] ?? 100);
        $this->endY = $this->clampY($p['endY'] ?? 100);
        $this->color = (string) ($p['color'] ?? '#333333');
        $this->width = (int) max(1, min($p['width'] ?? 2, 10));
        $this->style = in_array($p['style'] ?? '', ['solid', 'dashed'], true) ? $p['style'] : 'solid';
        $this->points = is_array($p['points'] ?? null) ? $p['points'] : ['', ''];
        $this->elementId = $p['elementId'] ?? null;
    }

    public function validate(): void {}

    public function isSynchronous(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'startX' => $this->startX,
            'startY' => $this->startY,
            'endX' => $this->endX,
            'endY' => $this->endY,
            'color' => $this->color,
            'width' => $this->width,
            'style' => $this->style,
            'points' => $this->points,
            'elementId' => $this->elementId,
        ]);
    }
}

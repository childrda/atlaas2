<?php

namespace App\Actions\Classroom;

class WbDrawChart extends BaseAction
{
    public const ALLOWED_TYPES = ['bar', 'column', 'line', 'pie', 'ring', 'area', 'radar', 'scatter'];

    public string $chartType;

    public int $x;

    public int $y;

    public int $width;

    public int $height;

    /** @var array<string, mixed> */
    public array $data;

    /** @var list<string> */
    public array $themeColors;

    public ?string $elementId;

    public function __construct(array $params = [])
    {
        parent::__construct('wb_draw_chart', $params);
    }

    protected function hydrateParams(array $p): void
    {
        $this->chartType = in_array($p['chartType'] ?? '', self::ALLOWED_TYPES, true)
            ? $p['chartType'] : 'bar';
        $this->x = $this->clampX($p['x'] ?? 100);
        $this->y = $this->clampY($p['y'] ?? 100);
        $this->width = $this->clampDim($p['width'] ?? 400, 1000);
        $this->height = $this->clampDim($p['height'] ?? 250, 562);
        $this->data = is_array($p['data'] ?? null)
            ? $p['data']
            : ['labels' => [], 'legends' => [], 'series' => [[]]];
        $this->themeColors = is_array($p['themeColors'] ?? null)
            ? $p['themeColors']
            : ['#5b9bd5', '#ed7d31', '#a9d18e'];
        $this->elementId = $p['elementId'] ?? null;
    }

    public function validate(): void
    {
        if (empty($this->data['labels'])) {
            throw new \InvalidArgumentException('WbDrawChart requires at least one label');
        }
    }

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
            'chartType' => $this->chartType,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
            'data' => $this->data,
            'themeColors' => $this->themeColors,
            'elementId' => $this->elementId,
        ]);
    }
}

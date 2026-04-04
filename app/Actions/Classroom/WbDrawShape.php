<?php

namespace App\Actions\Classroom;

class WbDrawShape extends BaseAction
{
    public const ALLOWED_SHAPES = ['rectangle', 'circle', 'triangle'];

    public string $shape;

    public int $x;

    public int $y;

    public int $width;

    public int $height;

    public string $fillColor;

    public ?string $elementId;

    public function __construct(array $params = [])
    {
        parent::__construct('wb_draw_shape', $params);
    }

    protected function hydrateParams(array $p): void
    {
        $this->shape = in_array($p['shape'] ?? '', self::ALLOWED_SHAPES, true)
            ? $p['shape'] : 'rectangle';
        $this->x = $this->clampX($p['x'] ?? 200);
        $this->y = $this->clampY($p['y'] ?? 150);
        $this->width = $this->clampDim($p['width'] ?? 200, 1000);
        $this->height = $this->clampDim($p['height'] ?? 100, 562);
        $this->fillColor = (string) ($p['fillColor'] ?? '#5b9bd5');
        $this->elementId = $p['elementId'] ?? null;

        if ($this->x + $this->width > 1000) {
            $this->width = 1000 - $this->x;
        }
        if ($this->y + $this->height > 562) {
            $this->height = 562 - $this->y;
        }
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
            'shape' => $this->shape,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
            'fillColor' => $this->fillColor,
            'elementId' => $this->elementId,
        ]);
    }
}

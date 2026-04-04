<?php

namespace App\Actions\Classroom;

class WbDrawText extends BaseAction
{
    public string $content;

    public int $x;

    public int $y;

    public int $width;

    public int $height;

    public int $fontSize;

    public string $color;

    public ?string $elementId;

    public function __construct(array $params = [])
    {
        parent::__construct('wb_draw_text', $params);
    }

    protected function hydrateParams(array $p): void
    {
        $this->content = (string) ($p['content'] ?? '');
        $this->x = $this->clampX($p['x'] ?? 100);
        $this->y = $this->clampY($p['y'] ?? 100);
        $this->width = $this->clampDim($p['width'] ?? 400, 1000);
        $this->height = $this->clampDim($p['height'] ?? 100, 562);
        $this->fontSize = (int) max(10, min($p['fontSize'] ?? 18, 72));
        $this->color = (string) ($p['color'] ?? '#333333');
        $this->elementId = $p['elementId'] ?? null;

        if ($this->x + $this->width > 1000) {
            $this->width = 1000 - $this->x;
        }
        if ($this->y + $this->height > 562) {
            $this->height = 562 - $this->y;
        }
    }

    public function validate(): void
    {
        if (trim($this->content) === '') {
            throw new \InvalidArgumentException('WbDrawText requires non-empty content');
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
            'content' => $this->content,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
            'fontSize' => $this->fontSize,
            'color' => $this->color,
            'elementId' => $this->elementId,
        ]);
    }
}

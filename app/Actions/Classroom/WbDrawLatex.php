<?php

namespace App\Actions\Classroom;

class WbDrawLatex extends BaseAction
{
    public string $latex;

    public int $x;

    public int $y;

    public int $width;

    public int $height;

    public string $color;

    public string $align;

    public ?string $elementId;

    public function __construct(array $params = [])
    {
        parent::__construct('wb_draw_latex', $params);
    }

    protected function hydrateParams(array $p): void
    {
        $this->latex = (string) ($p['latex'] ?? '');
        $this->x = $this->clampX($p['x'] ?? 100);
        $this->y = $this->clampY($p['y'] ?? 100);
        $this->width = $this->clampDim($p['width'] ?? 400, 1000);
        $this->height = $this->clampDim($p['height'] ?? 80, 300);
        $this->color = (string) ($p['color'] ?? '#000000');
        $this->align = in_array($p['align'] ?? '', ['left', 'center', 'right'], true)
            ? $p['align'] : 'center';
        $this->elementId = $p['elementId'] ?? null;
    }

    public function validate(): void
    {
        if (trim($this->latex) === '') {
            throw new \InvalidArgumentException('WbDrawLatex requires non-empty latex');
        }
        if (str_contains($this->latex, '<script') || str_contains($this->latex, '<img')) {
            throw new \InvalidArgumentException('WbDrawLatex contains disallowed HTML');
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
            'latex' => $this->latex,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
            'color' => $this->color,
            'align' => $this->align,
            'elementId' => $this->elementId,
        ]);
    }
}

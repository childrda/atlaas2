<?php

namespace App\Actions\Classroom;

class WbDrawTable extends BaseAction
{
    public int $x;

    public int $y;

    public int $width;

    public int $height;

    /** @var list<list<string>> */
    public array $data;

    public ?string $elementId;

    public function __construct(array $params = [])
    {
        parent::__construct('wb_draw_table', $params);
    }

    protected function hydrateParams(array $p): void
    {
        $this->x = $this->clampX($p['x'] ?? 100);
        $this->y = $this->clampY($p['y'] ?? 100);
        $this->width = $this->clampDim($p['width'] ?? 500, 1000);
        $this->height = $this->clampDim($p['height'] ?? 160, 562);
        $this->data = array_map(
            fn ($row) => array_map('strval', (array) $row),
            (array) ($p['data'] ?? [['Header']])
        );
        $this->elementId = $p['elementId'] ?? null;
    }

    public function validate(): void
    {
        if ($this->data === []) {
            throw new \InvalidArgumentException('WbDrawTable requires at least one row');
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
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
            'data' => $this->data,
            'elementId' => $this->elementId,
        ]);
    }
}

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
        $this->elementId = (string) ($p['elementId'] ?? '');
        $this->color = (string) ($p['color'] ?? '#ff0000');
    }

    public function validate(): void
    {
        if ($this->elementId === '') {
            throw new \InvalidArgumentException('Laser requires elementId');
        }
    }

    public function isSynchronous(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'elementId' => $this->elementId,
            'color' => $this->color,
        ]);
    }
}

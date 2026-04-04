<?php

namespace App\Actions\Classroom;

class Spotlight extends BaseAction
{
    public string $elementId;

    public float $dimOpacity;

    public function __construct(array $params = [])
    {
        parent::__construct('spotlight', $params);
    }

    protected function hydrateParams(array $p): void
    {
        $this->elementId = (string) ($p['elementId'] ?? '');
        $this->dimOpacity = (float) ($p['dimOpacity'] ?? 0.5);
    }

    public function validate(): void
    {
        if ($this->elementId === '') {
            throw new \InvalidArgumentException('Spotlight requires elementId');
        }
        $this->dimOpacity = max(0.1, min(0.9, $this->dimOpacity));
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
            'dimOpacity' => $this->dimOpacity,
        ]);
    }
}

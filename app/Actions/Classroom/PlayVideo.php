<?php

namespace App\Actions\Classroom;

class PlayVideo extends BaseAction
{
    public string $elementId;

    public function __construct(array $params = [])
    {
        parent::__construct('play_video', $params);
    }

    protected function hydrateParams(array $p): void
    {
        $this->elementId = (string) ($p['elementId'] ?? '');
    }

    public function validate(): void
    {
        if ($this->elementId === '') {
            throw new \InvalidArgumentException('play_video requires elementId');
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
            'elementId' => $this->elementId,
        ]);
    }
}

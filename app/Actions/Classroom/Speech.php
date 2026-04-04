<?php

namespace App\Actions\Classroom;

class Speech extends BaseAction
{
    public string $text;

    public ?string $voice;

    public float $speed;

    public function __construct(array $params = [])
    {
        parent::__construct('speech', $params);
    }

    protected function hydrateParams(array $p): void
    {
        $this->text = (string) ($p['text'] ?? $p['content'] ?? '');
        $this->voice = $p['voice'] ?? null;
        $this->speed = (float) ($p['speed'] ?? 1.0);
    }

    public function validate(): void
    {
        if (trim($this->text) === '') {
            throw new \InvalidArgumentException('Speech action requires non-empty text');
        }
        if ($this->speed < 0.5 || $this->speed > 2.0) {
            $this->speed = 1.0;
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
            'text' => $this->text,
            'voice' => $this->voice,
            'speed' => $this->speed,
        ]);
    }
}

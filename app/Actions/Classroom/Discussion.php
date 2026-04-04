<?php

namespace App\Actions\Classroom;

class Discussion extends BaseAction
{
    public string $topic;

    public ?string $prompt;

    public ?string $agentId;

    public function __construct(array $params = [])
    {
        parent::__construct('discussion', $params);
    }

    protected function hydrateParams(array $p): void
    {
        $this->topic = (string) ($p['topic'] ?? '');
        $this->prompt = $p['prompt'] ?? null;
        $this->agentId = $p['agentId'] ?? null;
    }

    public function validate(): void
    {
        if ($this->topic === '') {
            throw new \InvalidArgumentException('Discussion requires topic');
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
            'topic' => $this->topic,
            'prompt' => $this->prompt,
            'agentId' => $this->agentId,
        ]);
    }
}

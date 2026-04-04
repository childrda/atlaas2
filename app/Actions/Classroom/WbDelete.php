<?php

namespace App\Actions\Classroom;

class WbDelete extends BaseAction
{
    public string $elementId;

    public function __construct(array $params = [])
    {
        parent::__construct('wb_delete', $params);
    }

    protected function hydrateParams(array $p): void
    {
        $this->elementId = (string) ($p['elementId'] ?? '');
    }

    public function validate(): void
    {
        if ($this->elementId === '') {
            throw new \InvalidArgumentException('WbDelete requires elementId');
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

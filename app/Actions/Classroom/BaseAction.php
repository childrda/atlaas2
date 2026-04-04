<?php

namespace App\Actions\Classroom;

use Illuminate\Support\Str;

abstract class BaseAction
{
    public readonly string $id;

    public function __construct(
        public readonly string $type,
        array $params = [],
    ) {
        $this->id = 'act_'.Str::random(8);
        $this->hydrateParams($params);
    }

    abstract protected function hydrateParams(array $params): void;

    abstract public function validate(): void;

    abstract public function isSynchronous(): bool;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
        ];
    }

    protected function clampX(int|float $v): int
    {
        return (int) max(0, min($v, 1000));
    }

    protected function clampY(int|float $v): int
    {
        return (int) max(0, min($v, 562));
    }

    protected function clampDim(int|float $v, int $max = 1000): int
    {
        return (int) max(1, min($v, $max));
    }
}

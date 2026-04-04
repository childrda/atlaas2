<?php

namespace App\Actions\Classroom;

class WbClose extends BaseAction
{
    public function __construct(array $params = [])
    {
        parent::__construct('wb_close', $params);
    }

    protected function hydrateParams(array $p): void {}

    public function validate(): void {}

    public function isSynchronous(): bool
    {
        return true;
    }
}

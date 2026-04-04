<?php

namespace App\Services\Safety;

readonly class CrisisResult
{
    public function __construct(
        public bool $detected,
        public ?string $type,
        public ?string $severity,
    ) {}
}

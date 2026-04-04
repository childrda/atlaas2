<?php

namespace App\Services\AI;

readonly class FlagResult
{
    public function __construct(
        public bool $flagged,
        public string $category,
        public string $severity, // critical|high|medium|low
    ) {}
}

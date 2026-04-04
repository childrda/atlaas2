<?php

namespace App\Services\Safety;

readonly class ModeContext
{
    /**
     * @param  list<string>  $courseNames
     */
    public function __construct(
        public string $studentMode,
        public string $scopeDescription,
        public array $courseNames = [],
    ) {}
}

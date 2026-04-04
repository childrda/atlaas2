<?php

return [
    'max_turns_per_round' => (int) env('CLASSROOM_MAX_TURNS_PER_ROUND', 3),
    'max_rounds_without_input' => (int) env('CLASSROOM_MAX_ROUNDS_WITHOUT_INPUT', 2),
    'max_total_turns_per_session' => (int) env('CLASSROOM_MAX_TOTAL_TURNS_PER_SESSION', 60),
];

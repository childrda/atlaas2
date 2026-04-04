<?php

namespace App\Services\Safety;

class AcademicIntegrityGuard
{
    private const PATTERNS = [
        '/\b(write|complete|finish)\s+(my|the)\s+(essay|paper|assignment|homework|lab report)\b/i',
        '/\b(do my homework for me|do the assignment for me|give me the answers)\b/i',
        '/\b(plagiarize|copy.?paste|turn in as my own)\b/i',
        '/\b(ai to submit|submit this for me|write it so i can turn it in)\b/i',
    ];

    public function shouldBlock(string $message): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }

    public function response(): string
    {
        return "I can help you understand ideas, outline your thinking, and check your work — but the words and final work need to be yours. "
            .'What part of the topic are you trying to figure out?';
    }
}

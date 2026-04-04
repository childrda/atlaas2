<?php

namespace App\Services\Classroom;

class PromptAddendumSanitizer
{
    public const MAX_LENGTH = 2000;

    /**
     * Strip prompt-injection patterns and unsafe markup from teacher-provided addendum text.
     */
    public static function sanitize(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $text = trim($raw);
        if ($text === '') {
            return null;
        }

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $blocked = [
            '/ignore\s+(all\s+)?(previous|prior)\s+instructions?/iu',
            '/disregard\s+(all\s+)?(previous|prior)/iu',
            '/you\s+are\s+now\s+/iu',
            '/new\s+instructions?\s*:/iu',
            '/\bsystem\s*:\s*/iu',
            '/<\|.*?\|>/u',
        ];

        foreach ($blocked as $pattern) {
            $text = preg_replace($pattern, '', $text) ?? $text;
        }

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        if (mb_strlen($text) > self::MAX_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_LENGTH);
        }

        return $text === '' ? null : $text;
    }
}

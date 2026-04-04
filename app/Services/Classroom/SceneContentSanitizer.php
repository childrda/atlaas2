<?php

namespace App\Services\Classroom;

class SceneContentSanitizer
{
    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function sanitizeForType(string $sceneType, array $content): array
    {
        return match ($sceneType) {
            'interactive' => self::sanitizeInteractive($content),
            default => $content,
        };
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private static function sanitizeInteractive(array $content): array
    {
        if (! isset($content['html']) || ! is_string($content['html'])) {
            return $content;
        }

        $html = $content['html'];
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/\bon\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/javascript\s*:/i', '', $html) ?? $html;
        if (mb_strlen($html) > 500_000) {
            $html = mb_substr($html, 0, 500_000);
        }
        $content['html'] = $html;

        return $content;
    }
}

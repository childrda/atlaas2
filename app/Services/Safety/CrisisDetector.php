<?php

namespace App\Services\Safety;

use Illuminate\Foundation\Application;

class CrisisDetector
{
    private const SELF_HARM_PATTERNS = [
        '/\b(want|going|trying|plan|thinking about)\b.{0,30}\b(hurt|kill|end|harm)\b.{0,20}\b(my?self|myself|me)\b/i',
        '/\b(suicide|suicidal|kill myself|end my life|don\'t want to (live|be here|exist))\b/i',
        '/\b(cutting|self[- ]harm|self[- ]injur)\b/i',
        '/\bnobody (would|will) (miss|care about) me\b/i',
        '/\b(hate my life|life (isn\'t|is not) worth)\b/i',
    ];

    private const HARM_TO_OTHERS_PATTERNS = [
        '/\b(want|going|plan|trying)\b.{0,20}\b(hurt|kill|attack|fight|stab|shoot)\b.{0,30}\b(him|her|them|someone|person|teacher|student|[a-z]+)\b/i',
        '/how (do i|to|can i) (hurt|poison|kill|attack) (a |an |the |my )?(person|someone|[a-z]+)\b/i',
    ];

    private const WEAPONS_PATTERNS = [
        '/how (do i|to|can i) (make|build|create|get|buy).{0,20}(bomb|explosive|gun|weapon|knife|poison)\b/i',
        '/\b(thermite|pipe bomb|zip gun|ghost gun|IED|Molotov)\b/i',
    ];

    private const ABUSE_PATTERNS = [
        '/\b(my (mom|dad|stepdad|stepmom|uncle|aunt|teacher|coach|[a-z]+)) (touches|hurts|hits|beats|abuses) me\b/i',
        '/\b(someone is (hurting|abusing|touching) me)\b/i',
        '/\b(scared to go home|afraid of (my|the))\b/i',
    ];

    private const IMMEDIATE_DANGER_PATTERNS = [
        '/\b(gun|weapon|knife|shooter)\b.{0,30}\b(at school|in class|in the building)\b/i',
        '/\b(active shooter|lockdown|someone has a (gun|weapon))\b/i',
        '/\b(i\'m being followed|i am being followed|i\'m in danger|i am in danger)\b/i',
    ];

    public function detect(string $message): CrisisResult
    {
        if (! $this->crisisDetectionEnabled()) {
            return new CrisisResult(false, null, null);
        }

        $normalized = strtolower($message);

        foreach (self::SELF_HARM_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return new CrisisResult(true, 'self_harm', 'critical');
            }
        }
        foreach (self::HARM_TO_OTHERS_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return new CrisisResult(true, 'harm_to_others', 'critical');
            }
        }
        foreach (self::WEAPONS_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return new CrisisResult(true, 'weapons', 'critical');
            }
        }
        foreach (self::ABUSE_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return new CrisisResult(true, 'abuse_signal', 'critical');
            }
        }
        foreach (self::IMMEDIATE_DANGER_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return new CrisisResult(true, 'immediate_danger', 'critical');
            }
        }

        return new CrisisResult(false, null, null);
    }

    private function crisisDetectionEnabled(): bool
    {
        try {
            if (function_exists('app')) {
                $app = app();
                if ($app instanceof Application && $app->isBooted()) {
                    return (bool) config('atlaas.crisis_detection_enabled', true);
                }
            }
        } catch (\Throwable) {
        }

        $raw = $_ENV['ATLAAS_CRISIS_DETECTION_ENABLED'] ?? getenv('ATLAAS_CRISIS_DETECTION_ENABLED');

        return $raw === null || $raw === false
            ? true
            : filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }
}

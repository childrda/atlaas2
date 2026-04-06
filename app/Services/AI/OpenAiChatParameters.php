<?php

namespace App\Services\AI;

/**
 * Newer OpenAI chat models reject max_tokens and require max_completion_tokens.
 */
final class OpenAiChatParameters
{
    /**
     * @param  array<string, mixed>  $payload  Must include 'model' for auto-detection when explicit config is unset.
     * @return array<string, mixed>
     */
    public static function withMaxOutputTokens(array $payload, int $maxOutputTokens): array
    {
        $key = self::maxOutputTokenKey(is_string($payload['model'] ?? null) ? (string) $payload['model'] : null);
        $payload[$key] = $maxOutputTokens;

        return $payload;
    }

    public static function maxOutputTokenKey(?string $model = null): string
    {
        $explicit = config('openai.chat_completion_token_param');
        if (is_string($explicit) && $explicit !== '') {
            return in_array($explicit, ['max_tokens', 'max_completion_tokens'], true)
                ? $explicit
                : 'max_tokens';
        }

        $m = strtolower($model ?? (string) config('openai.model', ''));

        if ($m === '') {
            return 'max_tokens';
        }

        // Reasoning / newer families (API: use max_completion_tokens instead of max_tokens)
        if (preg_match('/^(o[0-9]+|gpt-5|gpt-4\.1)/i', $m)) {
            return 'max_completion_tokens';
        }

        if (str_contains($m, 'reasoning')) {
            return 'max_completion_tokens';
        }

        return 'max_tokens';
    }
}

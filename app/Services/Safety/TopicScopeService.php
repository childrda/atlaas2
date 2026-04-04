<?php

namespace App\Services\Safety;

use App\Services\AI\ChatCompletionClient;
use Illuminate\Support\Str;

class TopicScopeService
{
    public function __construct(private ChatCompletionClient $llm) {}

    public function isOnTopic(string $studentMessage, ModeContext $ctx): bool
    {
        if (! config('atlaas.topic_scope_llm_enabled', true)) {
            return true;
        }

        if ($ctx->studentMode === 'open_tutor') {
            return true;
        }

        $system = <<<'SYS'
You classify whether a K-12 student's chat message is on-topic for the given teaching scope.
Reply with ONLY compact JSON: {"in_scope":true} or {"in_scope":false}.
No markdown, no explanation.
SYS;

        $user = "Scope:\n{$ctx->scopeDescription}\n\nStudent message:\n".Str::limit($studentMessage, 1500);

        $raw = trim($this->llm->complete($system, $user, 120));
        if ($raw === '') {
            return true;
        }

        $json = $this->extractJson($raw);
        if (! is_array($json)) {
            return true;
        }

        return (bool) ($json['in_scope'] ?? true);
    }

    public function offTopicRedirect(): string
    {
        return "Let's keep this focused on your school learning. What part of the lesson or your coursework can I help with?";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJson(string $raw): ?array
    {
        if (preg_match('/\{[^}]+\}/', $raw, $m)) {
            $decoded = json_decode($m[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}

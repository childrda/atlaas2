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

Count as ON-TOPIC (in_scope:true) when the message asks for help with the scoped subject, including:
- diagrams, illustrations, images, pictures, charts, or other visual aids about the topic
- explanations, examples, analogies, practice questions, summaries, or step-by-step help
- clarifying vocabulary or concepts tied to the scope

Count as OFF-TOPIC (in_scope:false) only when the message is clearly unrelated to the scope, casual non-academic chit-chat, or asks for disallowed help (e.g. completing graded work for them) with no legitimate learning angle.

Reply with ONLY compact JSON: {"in_scope":true} or {"in_scope":false}.
No markdown fences, no explanation.
SYS;

        $user = "Scope:\n{$ctx->scopeDescription}\n\nStudent message:\n".Str::limit($studentMessage, 1500);

        try {
            $raw = trim($this->llm->complete($system, $user, 120));
        } catch (\Throwable) {
            return true;
        }
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
        $raw = trim($raw);
        if (preg_match('/^```(?:json)?\s*(\{.*\})\s*```$/s', $raw, $m)) {
            $raw = $m[1];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/', $raw, $m)) {
            $decoded = json_decode($m[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}

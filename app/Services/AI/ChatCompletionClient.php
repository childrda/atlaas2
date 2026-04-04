<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;

/**
 * OpenAI-compatible chat completions (non-streaming and streaming).
 * Used by classroom / lesson generation so settings come from config/openai.php.
 */
class ChatCompletionClient
{
    public function complete(string $systemPrompt, string $userPrompt, ?int $maxTokens = null): string
    {
        $maxTokens ??= (int) config('openai.max_output_tokens', 2000);
        $payload = [
            'model' => config('openai.model'),
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'max_tokens' => $maxTokens,
            'temperature' => (float) config('openai.temperature', 0.7),
        ];

        $response = $this->http()->post($this->endpoint(), $payload);

        if (! $response->successful()) {
            return '';
        }

        return (string) ($response->json('choices.0.message.content') ?? '');
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return \Generator<string>
     */
    public function stream(string $systemPrompt, array $messages, ?int $maxTokens = null): \Generator
    {
        $maxTokens ??= (int) config('openai.classroom_stream_max_tokens', 2000);
        $payload = [
            'model' => config('openai.model'),
            'max_tokens' => $maxTokens,
            'temperature' => (float) config('openai.temperature', 0.7),
            'stream' => true,
            'messages' => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $messages,
            ),
        ];

        $response = $this->http()->withOptions(['stream' => true])->post($this->endpoint(), $payload);

        if (! $response->successful()) {
            return;
        }

        $buffer = '';
        $body = $response->toPsrResponse()->getBody();
        while (! $body->eof()) {
            $buffer .= $body->read(1024);
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines) ?? '';
            foreach ($lines as $line) {
                $line = trim($line);
                if (! str_starts_with($line, 'data: ')) {
                    continue;
                }
                $data = substr($line, 6);
                if ($data === '[DONE]') {
                    return;
                }
                $parsed = json_decode($data, true);
                if (! is_array($parsed)) {
                    continue;
                }
                $delta = (string) ($parsed['choices'][0]['delta']['content'] ?? '');
                if ($delta !== '') {
                    yield $delta;
                }
            }
        }
    }

    private function endpoint(): string
    {
        $base = rtrim((string) config('openai.base_uri'), '/');
        if ($base === '') {
            $base = 'https://api.openai.com/v1';
        }
        if (! str_ends_with($base, '/v1')) {
            $base .= '/v1';
        }

        return $base.'/chat/completions';
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];
        $key = config('openai.api_key');
        if (! empty($key)) {
            $headers['Authorization'] = 'Bearer '.$key;
        }
        $org = config('openai.organization');
        if (! empty($org)) {
            $headers['OpenAI-Organization'] = $org;
        }

        return Http::withHeaders($headers)
            ->timeout((int) config('openai.request_timeout', 60));
    }
}

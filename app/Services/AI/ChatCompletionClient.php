<?php

namespace App\Services\AI;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OpenAI-compatible chat completions (non-streaming and streaming).
 * Used by classroom / lesson generation so settings come from config/openai.php.
 */
class ChatCompletionClient
{
    public function complete(string $systemPrompt, string $userPrompt, ?int $maxTokens = null): string
    {
        $this->ensureApiKeyIfRequired();

        $maxTokens ??= (int) config('openai.max_output_tokens', 2000);
        $payload = OpenAiChatParameters::withMaxOutputTokens([
            'model' => config('openai.model'),
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => (float) config('openai.temperature', 0.7),
        ], $maxTokens);

        $response = $this->http()->post($this->endpoint(), $payload);

        if (! $response->successful()) {
            $json = $response->json();
            $detail = is_array($json)
                ? (string) ($json['error']['message'] ?? json_encode($json))
                : $response->body();
            Log::warning('OpenAI-compatible chat completion failed', [
                'status' => $response->status(),
                'body' => Str::limit($detail, 800),
            ]);
            throw new \RuntimeException(
                'LLM request failed ('.$response->status().'): '.Str::limit($detail, 300)
            );
        }

        return (string) ($response->json('choices.0.message.content') ?? '');
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return \Generator<string>
     */
    public function stream(string $systemPrompt, array $messages, ?int $maxTokens = null): \Generator
    {
        $this->ensureApiKeyIfRequired();

        $maxTokens ??= (int) config('openai.classroom_stream_max_tokens', 2000);
        $payload = OpenAiChatParameters::withMaxOutputTokens([
            'model' => config('openai.model'),
            'temperature' => (float) config('openai.temperature', 0.7),
            'stream' => true,
            'messages' => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $messages,
            ),
        ], $maxTokens);

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

    /**
     * OpenAI-hosted endpoints require a key; local OpenAI-compatible servers often omit it.
     */
    private function ensureApiKeyIfRequired(): void
    {
        $key = trim((string) (config('openai.api_key') ?? ''));
        if ($key !== '') {
            return;
        }

        $base = strtolower(rtrim((string) config('openai.base_uri'), '/'));
        $isOpenAiHosted = $base === '' || str_contains($base, 'api.openai.com');

        if ($isOpenAiHosted) {
            throw new \RuntimeException(
                'OPENAI_API_KEY is empty. Lesson generation uses ChatCompletionClient against api.openai.com. '.
                'Set OPENAI_API_KEY in .env, run `php artisan config:clear`, then restart your queue worker (`queue:work` or Horizon).'
            );
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

    private function http(): PendingRequest
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

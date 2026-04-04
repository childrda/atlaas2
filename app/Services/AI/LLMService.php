<?php

namespace App\Services\AI;

use App\Events\MessageSent;
use App\Jobs\ProcessSafetyAlert;
use App\Models\Message;
use App\Models\StudentSession;
use App\Services\Safety\StudentChatSafetyCoordinator;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;

class LLMService
{
    public function __construct(
        private SafetyFilter $safety,
        private PrivacyFilter $privacy,
        private PromptBuilder $promptBuilder,
        private ResponseParser $parser,
        private ImageService $images,
        private DiagramGenerator $diagrams,
        private StudentChatSafetyCoordinator $studentChatSafety,
    ) {}

    /**
     * Parse stored assistant text and enrich for display (session reload, etc.).
     *
     * @return list<array<string, mixed>>
     */
    public function parseAndEnrichForDisplay(string $assistantContent): array
    {
        $segments = $this->parser->parse($assistantContent);

        return $this->enrichSegments($segments, config('atlaas.image_source', 'wikimedia'));
    }

    /**
     * @param  callable(string): void  $onChunk
     * @param  callable(list<array<string, mixed>>): void  $onComplete
     */
    public function streamResponse(
        StudentSession $session,
        string $userMessage,
        callable $onChunk,
        callable $onComplete,
    ): void {
        [$preFlag, $earlyReply] = $this->studentChatSafety->evaluateBeforeLlm($session, $userMessage);
        if ($earlyReply !== null) {
            if (
                $preFlag
                && ! str_starts_with($preFlag->category, 'crisis_')
                && $preFlag->category !== 'academic_integrity'
                && $preFlag->category !== 'cheating_request'
                && $preFlag->category !== 'scope:off_topic'
                && in_array($preFlag->severity, ['critical', 'high'], true)
            ) {
                ProcessSafetyAlert::dispatch($session, $preFlag, $userMessage);
            }

            $this->storeMessages($session, $userMessage, $earlyReply, $preFlag);
            $onChunk($earlyReply);
            $onComplete([['type' => 'text', 'content' => $earlyReply]]);

            return;
        }

        $flag = $this->safety->check($userMessage);
        if ($flag && in_array($flag->severity, ['critical', 'high'], true)) {
            $safeResponse = $this->safety->safeAtlaasResponse($flag->category);
            $this->storeMessages($session, $userMessage, $safeResponse, $flag);
            ProcessSafetyAlert::dispatch($session, $flag, $userMessage);
            $onChunk($safeResponse);
            $onComplete([['type' => 'text', 'content' => $safeResponse]]);

            return;
        }

        $cleanMessage = $this->privacy->clean($userMessage);

        $history = $session->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->latest()
            ->limit(20)
            ->get()
            ->reverse()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->toArray();

        $systemPrompt = $this->promptBuilder->build($session->space, $session->student);
        $modeAppendix = $this->studentChatSafety->systemPromptAppendix($session);
        if ($modeAppendix !== '') {
            $systemPrompt .= "\n\n---\n\n# Mode and topic scope\n".$modeAppendix;
        }

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ...$history,
            ['role' => 'user', 'content' => $cleanMessage],
        ];

        $fullResponse = '';

        $stream = OpenAI::chat()->createStreamed([
            'model' => config('openai.model'),
            'messages' => $messages,
            'max_tokens' => 800,
            'temperature' => 0.7,
        ]);

        foreach ($stream as $response) {
            $choice = $response->choices[0] ?? null;
            $chunk = $choice?->delta->content ?? '';
            if ($chunk !== '') {
                $fullResponse .= $chunk;
                $onChunk($chunk);
            }
        }

        $responseFlag = $this->safety->check($fullResponse);
        if ($responseFlag && in_array($responseFlag->severity, ['critical', 'high'], true)) {
            $fullResponse = "I'm not able to help with that. Let's focus on your learning today.";
        }

        $this->storeMessages($session, $userMessage, $fullResponse, $flag);

        $segments = $this->parser->parse($fullResponse);
        $enriched = $this->enrichSegments($segments, config('atlaas.image_source', 'wikimedia'));

        $onComplete($enriched);
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @return list<array<string, mixed>>
     */
    private function enrichSegments(array $segments, ?string $imageSource): array
    {
        foreach ($segments as &$segment) {
            if ($segment['type'] === 'image') {
                $keyword = (string) ($segment['keyword'] ?? '');
                $segment['resolved'] = $this->images->resolve($keyword, $imageSource);
            }
            if ($segment['type'] === 'diagram') {
                $svg = $this->diagrams->generate(
                    (string) ($segment['diagram_type'] ?? 'steps'),
                    (string) ($segment['description'] ?? '')
                );
                $segment['svg'] = $svg;
            }
        }
        unset($segment);

        return $segments;
    }

    private function storeMessages(
        StudentSession $session,
        string $userContent,
        string $assistantContent,
        ?FlagResult $flag
    ): void {
        $storedUserContent = $this->privacy->clean($userContent);
        $userRowContent = ($flag && str_starts_with($flag->category, 'crisis_'))
            ? '[Message removed — pending safety review]'
            : $storedUserContent;

        Message::insert([
            [
                'id' => (string) Str::uuid(),
                'session_id' => $session->id,
                'district_id' => $session->district_id,
                'role' => 'user',
                'content' => $userRowContent,
                'flagged' => $flag !== null,
                'flag_reason' => $flag?->category,
                'flag_category' => $flag?->severity,
                'tokens' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'session_id' => $session->id,
                'district_id' => $session->district_id,
                'role' => 'assistant',
                'content' => $assistantContent,
                'flagged' => false,
                'flag_reason' => null,
                'flag_category' => null,
                'tokens' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $session->increment('message_count', 2);
        $session->refresh();
        $session->load(['student', 'space']);
        $preview = ($flag && str_starts_with($flag->category, 'crisis_'))
            ? '[Safety review — message withheld]'
            : substr($storedUserContent, 0, 80);
        MessageSent::dispatch($session, $preview);
    }
}

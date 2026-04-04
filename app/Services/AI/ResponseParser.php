<?php

namespace App\Services\AI;

class ResponseParser
{
    private const TAG_PATTERN = '/^\[(IMAGE|DIAGRAM|FUN_FACT|QUIZ):(.+)\]\s*$/';

    /**
     * Parse raw LLM output into an ordered array of typed segments.
     *
     * @return list<array<string, mixed>>
     */
    public function parse(string $raw): array
    {
        $segments = [];
        $lines = explode("\n", $raw);
        $textBuffer = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match(self::TAG_PATTERN, $trimmed, $matches)) {
                if (trim($textBuffer) !== '') {
                    $segments[] = ['type' => 'text', 'content' => trim($textBuffer)];
                    $textBuffer = '';
                }

                $tagType = $matches[1];
                $tagBody = trim($matches[2]);
                $segment = $this->parseTag($tagType, $tagBody);
                if ($segment !== null) {
                    $segments[] = $segment;
                }
            } else {
                $textBuffer .= $line."\n";
            }
        }

        if (trim($textBuffer) !== '') {
            $segments[] = ['type' => 'text', 'content' => trim($textBuffer)];
        }

        return $this->enforceTagLimit($segments);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseTag(string $type, string $body): ?array
    {
        return match ($type) {
            'IMAGE' => [
                'type' => 'image',
                'keyword' => $this->sanitizeKeyword($body),
            ],
            'DIAGRAM' => $this->parseDiagramTag($body),
            'FUN_FACT' => [
                'type' => 'fun_fact',
                'content' => strip_tags($body),
            ],
            'QUIZ' => $this->parseQuizTag($body),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseDiagramTag(string $body): ?array
    {
        $parts = array_map('trim', explode('|', $body, 2));
        if (count($parts) < 2) {
            return null;
        }

        $validTypes = ['cycle', 'steps', 'compare', 'label'];
        $type = strtolower($parts[0]);
        if (! in_array($type, $validTypes, true)) {
            $type = 'steps';
        }

        return [
            'type' => 'diagram',
            'diagram_type' => $type,
            'description' => strip_tags($parts[1]),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseQuizTag(string $body): ?array
    {
        $parts = array_map('trim', explode('|', $body));
        if (count($parts) < 5) {
            return null;
        }

        $question = strip_tags($parts[0]);
        $options = [strip_tags($parts[1]), strip_tags($parts[2]), strip_tags($parts[3])];
        $answer = strip_tags($parts[4]);

        if (! in_array($answer, $options, true)) {
            return null;
        }

        return [
            'type' => 'quiz',
            'question' => $question,
            'options' => $options,
            'answer' => $answer,
        ];
    }

    private function sanitizeKeyword(string $keyword): string
    {
        $keyword = preg_replace('/https?:\/\/\S+/', '', $keyword) ?? '';
        $keyword = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $keyword) ?? '';

        return trim(mb_substr($keyword, 0, 100));
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @return list<array<string, mixed>>
     */
    private function enforceTagLimit(array $segments): array
    {
        $tagCount = 0;
        $result = [];

        foreach ($segments as $segment) {
            if ($segment['type'] !== 'text') {
                $tagCount++;
                if ($tagCount > 2) {
                    continue;
                }
            }
            $result[] = $segment;
        }

        return $result;
    }
}

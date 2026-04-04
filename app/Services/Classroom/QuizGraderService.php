<?php

namespace App\Services\Classroom;

use App\Models\LessonQuizAttempt;
use App\Services\AI\ChatCompletionClient;

class QuizGraderService
{
    public function __construct(
        private ChatCompletionClient $llm,
    ) {}

    /**
     * @param  array<string, mixed>  $question
     * @return array{is_correct: bool, score: float, max_score: float, feedback: string}
     */
    public function grade(LessonQuizAttempt $attempt, array $question): array
    {
        $type = $question['type'] ?? 'single';
        $maxScore = (float) ($question['points'] ?? 10);

        if (in_array($type, ['single', 'multiple'], true)) {
            return $this->gradeMultipleChoice($attempt, $question, $maxScore);
        }

        return $this->gradeShortAnswer($attempt, $question, $maxScore);
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array{is_correct: bool, score: float, max_score: float, feedback: string}
     */
    private function gradeMultipleChoice(LessonQuizAttempt $attempt, array $question, float $maxScore): array
    {
        $correct = array_map('strval', $question['answer'] ?? []);
        $given = array_map('strval', $attempt->student_answer ?? []);

        sort($correct);
        sort($given);

        $isCorrect = $correct === $given;
        $score = $isCorrect ? $maxScore : 0.0;

        return [
            'is_correct' => $isCorrect,
            'score' => $score,
            'max_score' => $maxScore,
            'feedback' => $isCorrect ? 'Correct!' : 'Review the explanation below.',
        ];
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array{is_correct: bool, score: float, max_score: float, feedback: string}
     */
    private function gradeShortAnswer(LessonQuizAttempt $attempt, array $question, float $maxScore): array
    {
        $raw = $attempt->student_answer ?? [];
        $studentAnswer = is_array($raw) ? implode("\n", $raw) : (string) $raw;

        $systemPrompt = 'You are a professional educational assessor. Grade the student answer. Reply in JSON only: {"score": 0-N, "comment": "one or two sentences"}';

        $guidance = (string) ($question['commentPrompt'] ?? 'Grade for conceptual understanding.');
        $userPrompt = "Question: {$question['question']}\nFull marks: {$maxScore} points\nGrading guidance: {$guidance}\nStudent answer: {$studentAnswer}";

        $response = $this->llm->complete($systemPrompt, $userPrompt, 200);

        if (preg_match('/\{[^}]+\}/s', $response, $m)) {
            $parsed = json_decode($m[0], true);
            if (is_array($parsed)) {
                $score = (int) max(0, min($maxScore, (int) ($parsed['score'] ?? 0)));
                $comment = (string) ($parsed['comment'] ?? 'Answer received.');
                $isCorrect = $score >= ($maxScore * 0.6);

                return [
                    'is_correct' => $isCorrect,
                    'score' => (float) $score,
                    'max_score' => $maxScore,
                    'feedback' => $comment,
                ];
            }
        }

        return [
            'is_correct' => false,
            'score' => $maxScore * 0.5,
            'max_score' => $maxScore,
            'feedback' => 'Answer received. Please review the correct approach.',
        ];
    }
}

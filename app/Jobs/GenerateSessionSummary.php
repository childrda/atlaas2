<?php

namespace App\Jobs;

use App\Models\StudentSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use OpenAI\Laravel\Facades\OpenAI;

class GenerateSessionSummary implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'default';

    public int $tries = 2;

    public function __construct(public StudentSession $session) {}

    public function handle(): void
    {
        if ($this->session->message_count < 4) {
            return;
        }

        $transcript = $this->session->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => ($m->role === 'user' ? 'Student' : 'ATLAAS').': '.$m->content)
            ->join("\n\n");

        $studentSummary = OpenAI::chat()->create([
            'model' => config('openai.model'),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You write 2-3 sentence learning summaries for K-12 students. '.
                        'Use "you" to address the student directly. Be specific about what they explored. '.
                        'Be warm and encouraging. Focus on what they did well and learned.',
                ],
                [
                    'role' => 'user',
                    'content' => "Write a summary of this learning session:\n\n{$transcript}",
                ],
            ],
            'max_tokens' => 150,
        ])->choices[0]->message->content;

        $teacherSummary = OpenAI::chat()->create([
            'model' => config('openai.model'),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You write 2-3 sentence session summaries for teachers. '.
                        'Cover: what concepts the student engaged with, any signs of confusion or struggle, '.
                        'and one suggested next step. Be specific and professional.',
                ],
                [
                    'role' => 'user',
                    'content' => "Summarize this student session:\n\n{$transcript}",
                ],
            ],
            'max_tokens' => 200,
        ])->choices[0]->message->content;

        $this->session->update([
            'student_summary' => $studentSummary,
            'teacher_summary' => $teacherSummary,
        ]);
    }
}

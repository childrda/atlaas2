<?php

namespace App\Jobs;

use App\Models\ClassroomLesson;
use App\Services\Classroom\LessonGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateLessonOutlineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 2;

    public function __construct(
        public string $lessonId,
    ) {}

    public function handle(LessonGeneratorService $generator): void
    {
        $lesson = ClassroomLesson::findOrFail($this->lessonId);
        $generator->generateOutline($lesson);

        $lesson->refresh();

        if ($lesson->generation_status === 'failed') {
            return;
        }

        foreach ($lesson->scenes as $scene) {
            GenerateSceneContentJob::dispatch($scene->id)->onQueue('default');
        }
    }

    public function failed(?\Throwable $e): void
    {
        $message = $e?->getMessage() ?? 'Unknown error';

        Log::error('GenerateLessonOutlineJob failed', [
            'lesson_id' => $this->lessonId,
            'exception' => $message,
        ]);

        ClassroomLesson::find($this->lessonId)?->update([
            'generation_status' => 'failed',
            'generation_progress' => [
                'message' => $message,
                'hint' => self::failureHint($message),
            ],
        ]);
    }

    private static function failureHint(string $message): string
    {
        if (str_contains($message, 'OPENAI_API_KEY')) {
            return 'Add the key to .env, run `php artisan config:clear`, restart the queue worker from the project directory so it reloads env.';
        }
        if (str_contains($message, '401') || stripos($message, 'invalid api key') !== false
            || stripos($message, 'incorrect api key') !== false) {
            return 'API rejected the request (401). Verify OPENAI_API_KEY, billing, and model name (OPENAI_MODEL). Restart queue workers after changing .env.';
        }
        if (str_contains($message, '429')) {
            return 'Rate limit or quota (429). Wait and retry, or check usage limits on your API account.';
        }

        return 'See storage/logs/laravel.log for this timestamp. Ensure `php artisan queue:work` runs on the default queue and can reach the internet (or your OPENAI_BASE_URL host).';
    }
}

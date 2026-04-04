<?php

namespace App\Jobs;

use App\Models\ClassroomLesson;
use App\Services\Classroom\LessonGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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

        foreach ($lesson->scenes as $scene) {
            GenerateSceneContentJob::dispatch($scene->id)->onQueue('default');
        }
    }

    public function failed(?\Throwable $e): void
    {
        ClassroomLesson::find($this->lessonId)?->update([
            'generation_status' => 'failed',
            'generation_progress' => ['message' => $e?->getMessage() ?? 'Unknown error'],
        ]);
    }
}

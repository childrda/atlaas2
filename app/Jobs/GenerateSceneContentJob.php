<?php

namespace App\Jobs;

use App\Events\LessonGenerationCompleted;
use App\Models\ClassroomLesson;
use App\Models\LessonScene;
use App\Services\Classroom\LessonGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateSceneContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 2;

    public function __construct(
        public string $sceneId,
    ) {}

    public function handle(LessonGeneratorService $generator): void
    {
        $scene = LessonScene::findOrFail($this->sceneId);
        $generator->generateSceneContent($scene);

        $lesson = $scene->lesson;
        $pending = $lesson->scenes()->whereIn('generation_status', ['pending', 'generating'])->count();
        $failed = $lesson->scenes()->where('generation_status', 'error')->count();
        $totalScenes = $lesson->scenes()->count();
        $ready = $lesson->scenes()->where('generation_status', 'ready')->count();

        $lesson->update([
            'generation_progress' => [
                'step' => 'generating_scenes',
                'progress' => (int) (30 + ($ready / max(1, $totalScenes)) * 60),
                'total_scenes' => $totalScenes,
                'scenes_generated' => $ready,
            ],
        ]);

        if ($pending === 0) {
            $lesson->update([
                'generation_status' => $failed > 0 && $ready === 0 ? 'failed' : 'completed',
                'generation_progress' => [
                    'step' => 'completed',
                    'progress' => 100,
                    'total_scenes' => $totalScenes,
                    'scenes_generated' => $ready,
                ],
            ]);

            $lesson->refresh();
            broadcast(new LessonGenerationCompleted($lesson));
        }
    }
}

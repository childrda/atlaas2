<?php

namespace App\Providers;

use App\Services\AI\ChatCompletionClient;
use App\Services\AI\DiagramGenerator;
use App\Services\AI\ImageService;
use App\Services\AI\LLMService;
use App\Services\AI\ResponseParser;
use App\Services\TTS\TTSService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ResponseParser::class);
        $this->app->singleton(ImageService::class);
        $this->app->singleton(DiagramGenerator::class);
        $this->app->singleton(LLMService::class);
        $this->app->singleton(ChatCompletionClient::class);
        $this->app->singleton(TTSService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ============================================================
        // DISTRICT SCOPING — CRITICAL — DO NOT SKIP
        // ============================================================
        // All queries on district-owned resources MUST be scoped to
        // the authenticated user's district_id.
        //
        // Add this to each model's booted() method in Phase 2+:
        //
        //   static::addGlobalScope('district', function ($query) {
        //       if (auth()->check()) {
        //           $query->where('district_id', auth()->user()->district_id);
        //       }
        //   });
        //
        // Models that need it: Classroom, LearningSpace, StudentSession,
        //                       Message, SafetyAlert, TeacherTool, ToolRun
        //
        // Models that do NOT: District, School, User (own scoping logic)
        //
        // Cross-district data leakage is a FERPA violation.
        // ============================================================
    }
}

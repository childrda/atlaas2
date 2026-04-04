<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\StudentSession;
use App\Services\TTS\TTSService;
use App\Services\TTS\VoiceMap;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TTSController extends Controller
{
    public function __construct(private TTSService $tts) {}

    public function speak(Request $request, StudentSession $session): Response
    {
        abort_unless($this->tts->isEnabled(), 404);

        abort_unless($session->student_id === auth()->id(), 403);

        abort_unless(in_array($session->status, ['active', 'completed'], true), 403);

        $request->validate([
            'text' => 'required|string|max:3000',
        ]);

        $student = auth()->user();
        $voice = VoiceMap::forLanguage($student->preferred_language ?? 'en');
        $speed = VoiceMap::speedForGrade($student->grade_level);

        try {
            $audioBody = $this->tts->synthesize(
                text: $request->input('text'),
                voice: $voice,
                speed: $speed,
            );

            return response($audioBody, 200, [
                'Content-Type' => 'audio/mpeg',
                'Cache-Control' => 'no-store',
                'Content-Disposition' => 'inline',
            ]);
        } catch (\RuntimeException) {
            return response('TTS unavailable', 503);
        }
    }
}

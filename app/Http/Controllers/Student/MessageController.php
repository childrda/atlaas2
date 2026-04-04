<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\StudentSession;
use App\Services\AI\LLMService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageController extends Controller
{
    public function store(Request $request, StudentSession $session): StreamedResponse
    {
        abort_unless($session->student_id === auth()->id(), 403);
        abort_unless($session->status === 'active', 422, 'Session is not active.');

        $request->validate(['content' => 'required|string|max:2000']);

        $session->loadMissing('space');
        abort_if($session->space === null, 410, 'This learning space is no longer available.');

        $maxMessages = $session->space->max_messages;
        if ($maxMessages && $session->message_count >= $maxMessages) {
            return response()->stream(function () {
                echo 'data: '.json_encode(['type' => 'limit_reached'])."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }, 200, $this->sseHeaders());
        }

        $session->load('space', 'student');
        $llm = app(LLMService::class);

        return response()->stream(
            function () use ($session, $request, $llm) {
                if (function_exists('apache_setenv')) {
                    @apache_setenv('no-gzip', '1');
                }
                @ini_set('output_buffering', 'off');
                @ini_set('zlib.output_compression', '0');
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }

                try {
                    $llm->streamResponse(
                        session: $session,
                        userMessage: $request->input('content'),
                        onChunk: function (string $chunk) {
                            echo 'data: '.json_encode(['type' => 'chunk', 'content' => $chunk])."\n\n";
                            if (ob_get_level() > 0) {
                                ob_flush();
                            }
                            flush();
                        },
                        onComplete: function (array $segments) {
                            echo 'data: '.json_encode(['type' => 'done', 'segments' => $segments])."\n\n";
                            if (ob_get_level() > 0) {
                                ob_flush();
                            }
                            flush();
                        },
                    );
                } catch (\Throwable $e) {
                    report($e);
                    echo 'data: '.json_encode([
                        'type' => 'error',
                        'message' => 'Something went wrong. Please try again.',
                    ])."\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            },
            200,
            $this->sseHeaders()
        );
    }

    /**
     * @return array<string, string>
     */
    private function sseHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ];
    }
}

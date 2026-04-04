<?php

namespace App\Services\TTS;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TTSService
{
    public function isEnabled(): bool
    {
        return (bool) config('services.tts.enabled', false);
    }

    /**
     * Request audio from Kokoro (OpenAI-compatible /v1/audio/speech).
     *
     * @return string Raw MP3 bytes
     *
     * @throws \RuntimeException When Kokoro errors or text is empty after cleaning
     */
    public function synthesize(string $text, string $voice, float $speed): string
    {
        $cleanText = $this->prepareText($text);

        if ($cleanText === '') {
            throw new \RuntimeException('No speakable text after cleaning.');
        }

        $baseUrl = rtrim((string) config('services.tts.url', 'http://localhost:8880'), '/');

        $response = Http::timeout(30)
            ->asJson()
            ->post($baseUrl.'/v1/audio/speech', [
                'model' => 'kokoro',
                'input' => $cleanText,
                'voice' => $voice,
                'speed' => $speed,
                'response_format' => 'mp3',
            ]);

        if (! $response->successful()) {
            Log::warning('Kokoro TTS failed', [
                'status' => $response->status(),
                'voice' => $voice,
            ]);

            throw new \RuntimeException('TTS service returned error: '.$response->status());
        }

        return $response->body();
    }

    private function prepareText(string $text): string
    {
        $text = preg_replace('/\[(IMAGE|DIAGRAM|FUN_FACT|QUIZ):[^\]]+\]/i', '', $text) ?? '';
        $text = preg_replace('/\*{1,3}(.*?)\*{1,3}/', '$1', $text) ?? '';
        $text = preg_replace('/#{1,6}\s+/', '', $text) ?? '';
        $text = preg_replace('/`{1,3}[^`]*`{1,3}/', '', $text) ?? '';
        $text = preg_replace('/https?:\/\/\S+/', '', $text) ?? '';
        $text = preg_replace('/\s+/', ' ', $text) ?? '';

        return trim($text);
    }
}

<?php

namespace App\Services\TTS;

class VoiceMap
{
    /**
     * Map language codes to Kokoro voice ids.
     *
     * @see https://github.com/remsky/kokoro-fastapi#voices
     */
    private static array $map = [
        'en' => 'af_heart',
        'en-gb' => 'bf_emma',
        'es' => 'ef_dora',
        'fr' => 'ff_siwis',
        'ja' => 'jf_alpha',
        'pt' => 'pf_dora',
        'pt-br' => 'pf_dora',
        'zh' => 'zf_xiaobei',
        'zh-cn' => 'zf_xiaobei',
    ];

    public static function forLanguage(string $langCode): string
    {
        $code = strtolower($langCode);

        if (isset(self::$map[$code])) {
            return self::$map[$code];
        }

        $prefix = explode('-', $code)[0];

        return self::$map[$prefix] ?? config('services.tts.voice', 'af_heart');
    }

    public static function speedForGrade(?string $grade): float
    {
        $baseSpeed = (float) config('services.tts.speed', 0.9);

        if ($grade === null || $grade === '') {
            return $baseSpeed;
        }

        $g = strtolower($grade);

        if (in_array($g, ['k', 'kindergarten', '1', '2'], true)) {
            return min($baseSpeed, 0.8);
        }

        if (in_array($grade, ['3', '4', '5'], true)) {
            return min($baseSpeed, 0.9);
        }

        return $baseSpeed;
    }
}

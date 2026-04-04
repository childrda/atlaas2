<?php

namespace App\Actions\Classroom;

use Illuminate\Support\Facades\Log;

class ActionFactory
{
    /** @var array<string, class-string<BaseAction>> */
    private const MAP = [
        'speech' => Speech::class,
        'spotlight' => Spotlight::class,
        'laser' => Laser::class,
        'play_video' => PlayVideo::class,
        'wb_open' => WbOpen::class,
        'wb_close' => WbClose::class,
        'wb_clear' => WbClear::class,
        'wb_delete' => WbDelete::class,
        'wb_draw_text' => WbDrawText::class,
        'wb_draw_shape' => WbDrawShape::class,
        'wb_draw_chart' => WbDrawChart::class,
        'wb_draw_latex' => WbDrawLatex::class,
        'wb_draw_table' => WbDrawTable::class,
        'wb_draw_line' => WbDrawLine::class,
        'discussion' => Discussion::class,
    ];

    public static function make(string $type, array $params): ?BaseAction
    {
        $class = self::MAP[$type] ?? null;
        if (! $class) {
            return null;
        }

        try {
            $action = new $class($params);
            $action->validate();

            return $action;
        } catch (\InvalidArgumentException $e) {
            Log::warning(
                "[ActionFactory] Invalid action '{$type}': ".$e->getMessage(),
                ['params' => $params]
            );

            return null;
        }
    }

    public static function speech(string $text, ?string $voice = null): Speech
    {
        $action = new Speech(['text' => $text, 'voice' => $voice]);
        $action->validate();

        return $action;
    }

    /**
     * @return list<string>
     */
    public static function knownTypes(): array
    {
        return array_keys(self::MAP);
    }
}

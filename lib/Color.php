<?php

declare(strict_types=1);

namespace KLXM\Scheduler;

/**
 * Farbhelfer: wählt zu einer Kalenderfarbe die besser lesbare Schriftfarbe.
 */
final class Color
{
    public const string DARK_TEXT = '#1b2530';
    public const string LIGHT_TEXT = '#ffffff';

    /** Ab dieser wahrgenommenen Helligkeit (0 bis 255) ist dunkle Schrift besser lesbar. */
    private const int BRIGHTNESS_THRESHOLD = 150;

    /**
     * Dunkle oder weiße Schrift für eine farbige Fläche.
     *
     * Bewusst nicht die Kontrastformel aus WCAG 2: Sie bevorzugt auf gesättigten Mitteltönen wie
     * Kalenderblau rechnerisch dunkle Schrift, die dort tatsächlich schlecht lesbar ist. Die
     * wahrgenommene Helligkeit (YIQ) entscheidet auf solchen Flächen für Weiß.
     */
    public static function textOn(?string $background): string
    {
        $rgb = self::parse($background);
        if (null === $rgb) {
            return self::LIGHT_TEXT;
        }

        $brightness = (299 * $rgb[0] + 587 * $rgb[1] + 114 * $rgb[2]) / 1000;

        return $brightness >= self::BRIGHTNESS_THRESHOLD ? self::DARK_TEXT : self::LIGHT_TEXT;
    }

    /**
     * @return array{int, int, int}|null
     */
    public static function parse(?string $color): ?array
    {
        $color = trim((string) $color);
        if (1 === preg_match('/^#([0-9a-f]{3})$/i', $color, $m)) {
            $color = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }
        if (1 === preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})/i', $color, $m)) {
            return [(int) hexdec($m[1]), (int) hexdec($m[2]), (int) hexdec($m[3])];
        }
        if (1 === preg_match('/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i', $color, $m)) {
            return [min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3])];
        }

        return null;
    }
}

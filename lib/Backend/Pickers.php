<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Backend;

use rex_addon;

/**
 * Datums- und Zeitfelder. Ist das Addon a11y_datetime_addon aktiv, übernimmt dessen barrierefreier
 * Picker; sonst bleiben es native Eingabefelder. Gespeichert wird in beiden Fällen JJJJ-MM-TT und HH:MM.
 */
final class Pickers
{
    public static function enhanced(): bool
    {
        return rex_addon::get('a11y_datetime_addon')->isAvailable();
    }

    /**
     * @param array<string, string|int|bool|null> $attributes
     */
    public static function date(string $name, ?string $value, array $attributes = []): string
    {
        if (!self::enhanced()) {
            return Html::input('date', $name, $value, $attributes);
        }

        return Html::input('text', $name, $value, [
            ...$attributes,
            'class' => trim(($attributes['class'] ?? 'form-control') . ' a11y_datetime'),
            'data-locale' => self::locale(),
            'data-dateFormat' => 'Y-m-d',
            'data-altFormat' => 'D, j. F Y',
            'data-allowInput' => 'true',
            'autocomplete' => 'off',
        ]);
    }

    /**
     * @param array<string, string|int|bool|null> $attributes
     */
    public static function time(string $name, ?string $value, array $attributes = []): string
    {
        if (!self::enhanced()) {
            return Html::input('time', $name, $value, $attributes);
        }

        $step = (int) ($attributes['step'] ?? 300);
        unset($attributes['step']);

        return Html::input('text', $name, $value, [
            ...$attributes,
            'class' => trim(($attributes['class'] ?? 'form-control') . ' a11y_datetime'),
            'data-locale' => self::locale(),
            'data-enableTime' => 'true',
            'data-noCalendar' => 'true',
            'data-dateFormat' => 'H:i',
            'data-altFormat' => 'H:i',
            'data-minuteIncrement' => (string) max(1, intdiv($step, 60)),
            'data-allowInput' => 'true',
            'autocomplete' => 'off',
        ]);
    }

    private static function locale(): string
    {
        return substr(\rex_i18n::getLocale(), 0, 2);
    }
}

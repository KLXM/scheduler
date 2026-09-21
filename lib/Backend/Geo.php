<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Backend;

use KLXM\Scheduler\I18n;
use KLXM\VectorMaps\Picker\PickerWidget;
use rex_addon;

/**
 * Koordinaten eines Ortes. Mit dem Addon vector_maps erscheint ein Kartenpicker mit Adresssuche,
 * sonst zwei schlichte Eingabefelder.
 */
final class Geo
{
    public static function hasMapPicker(): bool
    {
        return rex_addon::get('vector_maps')->isAvailable() && class_exists(PickerWidget::class);
    }

    public static function input(?float $latitude, ?float $longitude, string $id = 'scheduler-coordinates'): string
    {
        $value = (null !== $latitude && null !== $longitude) ? $latitude . ',' . $longitude : '';

        if (self::hasMapPicker()) {
            return PickerWidget::factory('coordinates', $id)
                ->setValue($value)
                ->setPlaceholder(I18n::t('location_coordinates_placeholder'))
                ->addClass('form-control')
                ->parse();
        }

        return Html::input('text', 'coordinates', $value, [
            'id' => $id,
            'placeholder' => I18n::t('location_coordinates_placeholder'),
            'inputmode' => 'decimal',
            'autocomplete' => 'off',
        ]);
    }

    /**
     * Liest "Breite,Länge". Auch ein Semikolon oder Leerzeichen als Trenner und Dezimalkommas
     * in der Form "51,5136; 7,4653" werden akzeptiert.
     *
     * @return array{?float, ?float, ?string} Breite, Länge, Fehlermeldung
     */
    public static function parse(string $raw): array
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return [null, null, null];
        }

        $parts = preg_split('/\s*;\s*|\s+/', $raw) ?: [];
        if (2 !== count($parts)) {
            $parts = explode(',', $raw);
        }
        $parts = array_map(static fn (string $part): string => str_replace(',', '.', trim($part, " ,\t")), $parts);

        if (2 !== count($parts) || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return [null, null, I18n::t('location_coordinates_invalid')];
        }

        [$latitude, $longitude] = [(float) $parts[0], (float) $parts[1]];
        if (abs($latitude) > 90 || abs($longitude) > 180) {
            return [null, null, I18n::t('location_coordinates_range')];
        }

        return [$latitude, $longitude, null];
    }
}

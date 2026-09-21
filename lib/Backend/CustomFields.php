<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Backend;

use KLXM\Scheduler\Api\BackendApi;
use KLXM\Scheduler\Domain\Enum\SchemaTarget;
use KLXM\Scheduler\Field\FormRenderer;
use KLXM\Scheduler\Field\ProcessedValues;
use KLXM\Scheduler\Field\ValueProcessor;
use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Scheduler;

/**
 * Custom Fields für Entities ohne eigene Übersetzungstabelle (Kalender, Orte).
 * Übersetzbare Werte liegen dort unter dem Schlüssel _translations im Custom-JSON.
 *
 * @internal
 */
final class CustomFields
{
    /**
     * @param array<string, mixed> $custom
     * @param array<string, string> $errors
     */
    public static function render(SchemaTarget $target, array $custom, array $errors = []): string
    {
        $html = new FormRenderer(Scheduler::schemas()->types, self::clangs())->render(
            Scheduler::schemas()->active($target),
            $custom,
            is_array($custom['_translations'] ?? null) ? $custom['_translations'] : [],
            $errors,
        );

        // Feldtypen wie der YForm-Datensatz brauchen die Adresse der Backend-API.
        $apiUrl = \rex_url::currentBackendPage(BackendApi::getUrlParams(), false);

        return '' === $html ? '' : '<div data-scheduler-api="' . Html::e($apiUrl) . '">' . Html::section(I18n::e('more_details'), $html) . '</div>';
    }

    /**
     * @param array<string, mixed> $existing
     * @return array{array<string, mixed>, array<string, string>} Werte und Fehler
     */
    public static function process(SchemaTarget $target, array $existing): array
    {
        $processed = new ValueProcessor(Scheduler::schemas()->types)->process(
            Scheduler::schemas()->active($target),
            rex_post('custom', 'array', []),
            rex_post('custom_lang', 'array', []),
            array_keys(self::clangs()),
            $existing,
        );

        return [self::merge($processed), $processed->errors];
    }

    /**
     * @return array<string, mixed>
     */
    private static function merge(ProcessedValues $processed): array
    {
        $values = $processed->values;
        unset($values['_translations']);
        if ([] !== $processed->translatedValues) {
            $values['_translations'] = $processed->translatedValues;
        }

        return $values;
    }

    /**
     * @return array<int, string> Start-Sprache zuerst
     */
    public static function clangs(): array
    {
        $clangs = [];
        foreach ([\rex_clang::getStartId(), ...\rex_clang::getAllIds()] as $clangId) {
            $clangs[$clangId] ??= \rex_clang::get($clangId)?->getName() ?? (string) $clangId;
        }

        return $clangs;
    }
}

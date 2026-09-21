<?php

declare(strict_types=1);

namespace KLXM\Scheduler;

/**
 * Übersetzungen des Addons.
 *
 * Im laufenden REDAXO kommen die Texte aus rex_i18n und lassen sich dort wie gewohnt überschreiben.
 * Ohne REDAXO, etwa in Unit-Tests, liest der Helfer die deutsche Sprachdatei selbst.
 *
 * Schlüssel stehen ohne das Präfix "scheduler_" im Code: I18n::t('event_saved').
 * Texte für die Web Components tragen das Präfix "js_" und gehen gesammelt an den Browser.
 */
final class I18n
{
    private const string PREFIX = 'scheduler_';

    /** @var array<string, array<string, string>> nach Sprachdatei */
    private static array $files = [];

    public static function t(string $key, string|int|float ...$args): string
    {
        if (class_exists(\rex_i18n::class, false)) {
            return \rex_i18n::rawMsg(self::PREFIX . $key, ...array_map(strval(...), $args));
        }

        $message = self::file('de_de')[self::PREFIX . $key] ?? '[' . self::PREFIX . $key . ']';
        foreach (array_values($args) as $index => $value) {
            $message = str_replace('{' . $index . '}', (string) $value, $message);
        }

        return $message;
    }

    /** Wie t(), aber für die Ausgabe in HTML maskiert. */
    public static function e(string $key, string|int|float ...$args): string
    {
        return htmlspecialchars(self::t($key, ...$args), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Alle Texte für die Web Components in der aktuellen Sprache, ohne Präfix.
     *
     * @return array<string, string>
     */
    public static function forJavaScript(): array
    {
        $texts = [];
        foreach (array_keys(self::file('de_de')) as $fullKey) {
            if (str_starts_with($fullKey, self::PREFIX . 'js_')) {
                $key = substr($fullKey, strlen(self::PREFIX . 'js_'));
                $texts[$key] = self::t('js_' . $key);
            }
        }

        return $texts;
    }

    /**
     * Text für die Ausgabe im Frontend: Die Sprache richtet sich nach der REDAXO-Sprache (clang), nicht nach dem Backend.
     */
    public static function front(string $key, string|int|float ...$args): string
    {
        if (!class_exists(\rex_i18n::class, false)) {
            return self::t($key, ...$args);
        }

        return \rex_i18n::rawMsgInLocale(self::PREFIX . $key, self::frontLocale(), ...array_map(strval(...), $args));
    }

    /** Sprachdatei passend zum Code der aktuellen Sprache, etwa "en" zu "en_gb". */
    public static function frontLocale(): string
    {
        $code = strtolower(str_replace('-', '_', \rex_clang::getCurrent()->getCode()));
        $locales = array_map(static fn (string $file): string => basename($file, '.lang'), glob(dirname(__DIR__) . '/lang/*.lang') ?: []);

        return array_find($locales, static fn (string $locale): bool => $locale === $code)
            ?? array_find($locales, static fn (string $locale): bool => str_starts_with($locale, substr($code, 0, 2) . '_'))
            ?? \rex_i18n::getLocale();
    }

    /** Sprachkürzel für Intl und die Picker, etwa "de". */
    public static function language(): string
    {
        return class_exists(\rex_i18n::class, false) ? substr(\rex_i18n::getLocale(), 0, 2) : 'de';
    }

    /**
     * @return array<string, string>
     */
    private static function file(string $locale): array
    {
        if (!isset(self::$files[$locale])) {
            self::$files[$locale] = [];
            foreach (file(dirname(__DIR__) . '/lang/' . $locale . '.lang', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (!str_starts_with(ltrim($line), '#') && str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    self::$files[$locale][trim($key)] = trim($value);
                }
            }
        }

        return self::$files[$locale];
    }
}

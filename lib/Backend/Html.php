<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Backend;

use KLXM\Scheduler\I18n;
use rex_fragment;

/**
 * HTML-Bausteine für die Backend-Seiten im Look des REDAXO-Backends.
 *
 * @internal
 */
final class Html
{
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Formularzeile mit Label, Eingabe, Hilfetext und Fehlermeldung.
     */
    public static function field(string $label, string $input, ?string $for = null, ?string $help = null, ?string $error = null, bool $required = false): string
    {
        return sprintf(
            '<div class="form-group scheduler-field%s"><label class="control-label"%s>%s%s</label><div class="scheduler-field-input">%s%s%s</div></div>',
            null !== $error ? ' has-error' : '',
            null !== $for ? ' for="' . self::e($for) . '"' : '',
            self::e($label),
            $required ? ' <abbr class="scheduler-required" title="' . I18n::e('required_field') . '">*</abbr>' : '',
            $input,
            null !== $help ? '<p class="help-block">' . self::e($help) . '</p>' : '',
            null !== $error ? '<p class="scheduler-error" role="alert">' . self::e($error) . '</p>' : '',
        );
    }

    /**
     * @param array<string, string|int|bool|null> $attributes
     */
    public static function input(string $type, string $name, ?string $value, array $attributes = []): string
    {
        return sprintf('<input type="%s" name="%s" value="%s"%s>', self::e($type), self::e($name), self::e($value), self::attributes(['class' => 'form-control', ...$attributes]));
    }

    /**
     * @param array<string|int, string> $options Wert => Beschriftung
     * @param array<string, string|int|bool|null> $attributes
     */
    public static function select(string $name, array $options, string|int|null $selected, array $attributes = [], ?string $placeholder = null): string
    {
        $html = '<select name="' . self::e($name) . '"' . self::attributes(['class' => 'form-control', ...$attributes]) . '>';
        if (null !== $placeholder) {
            $html .= '<option value="">' . self::e($placeholder) . '</option>';
        }
        foreach ($options as $value => $label) {
            $html .= sprintf('<option value="%s"%s>%s</option>', self::e((string) $value), (string) $value === (string) $selected ? ' selected' : '', self::e($label));
        }

        return $html . '</select>';
    }

    /**
     * @param array<string, string|int|bool|null> $attributes
     */
    public static function toggle(string $name, bool $checked, string $text, ?string $id = null, array $attributes = []): string
    {
        return sprintf(
            '<label class="scheduler-switch"><input type="hidden" name="%1$s" value="0"><input type="checkbox" role="switch" name="%1$s" value="1"%2$s> <span>%3$s</span></label>',
            self::e($name),
            self::attributes(['id' => $id, 'checked' => $checked, ...$attributes]),
            self::e($text),
        );
    }

    /**
     * @param array<string, string|int|bool|null> $attributes
     */
    public static function attributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $name => $value) {
            if (null === $value || false === $value) {
                continue;
            }
            $html .= true === $value ? ' ' . self::e($name) : sprintf(' %s="%s"', self::e($name), self::e((string) $value));
        }

        return $html;
    }

    /**
     * Abschnitt im REDAXO-Panel-Stil.
     */
    public static function section(string $title, string $body, string $buttons = '', string $class = 'edit', string $options = ''): string
    {
        $fragment = new rex_fragment();
        $fragment->setVar('class', $class, false);
        $fragment->setVar('title', $title, false);
        $fragment->setVar('options', $options, false);
        $fragment->setVar('body', $body, false);
        if ('' !== $buttons) {
            $fragment->setVar('buttons', $buttons, false);
        }

        return $fragment->parse('core/page/section.php');
    }

    /**
     * Leiste mit Abbrechen, Speichern und Übernehmen am Fuß eines Formulars.
     */
    public static function formActions(string $cancelUrl, string $extra = ''): string
    {
        return '<div class="scheduler-form-actions"><a class="btn btn-abort" href="' . self::e($cancelUrl) . '">' . I18n::e('cancel') . '</a> '
            . '<button class="btn btn-save" type="submit" name="save_and_close" value="1">' . I18n::e('save') . '</button> '
            . '<button class="btn btn-apply" type="submit">' . I18n::e('apply') . '</button>' . $extra . '</div>';
    }

    public static function activeState(bool $active): string
    {
        return $active
            ? '<span class="rex-online"><i class="rex-icon rex-icon-online"></i> ' . I18n::e('active') . '</span>'
            : '<span class="rex-offline"><i class="rex-icon rex-icon-offline"></i> ' . I18n::e('inactive') . '</span>';
    }

    public static function colorDot(?string $color): string
    {
        return '<span class="scheduler-dot" style="--scheduler-color:' . self::e($color ?? '#999') . '"></span>';
    }
}

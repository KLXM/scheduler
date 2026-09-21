<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Backend;

use rex_view;

/**
 * Meldungen, die einen Redirect überleben (Post/Redirect/Get).
 *
 * @internal
 */
final class Flash
{
    private const string KEY = 'scheduler_flash';

    public static function success(string $message): void
    {
        self::add('success', $message);
    }

    public static function error(string $message): void
    {
        self::add('error', $message);
    }

    public static function render(): string
    {
        $messages = rex_session(self::KEY, 'array', []);
        rex_unset_session(self::KEY);

        $html = '';
        foreach ($messages as [$type, $message]) {
            $html .= 'error' === $type ? rex_view::error(Html::e($message)) : rex_view::success(Html::e($message));
        }

        return $html;
    }

    private static function add(string $type, string $message): void
    {
        $messages = rex_session(self::KEY, 'array', []);
        $messages[] = [$type, $message];
        rex_set_session(self::KEY, $messages);
    }
}

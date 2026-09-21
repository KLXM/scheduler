<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Tests\Unit;

use KLXM\Scheduler\Color;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ColorTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function colors(): iterable
    {
        yield 'Standardblau' => ['#3788d8', Color::LIGHT_TEXT];
        yield 'dunkelblau' => ['#0d47a1', Color::LIGHT_TEXT];
        yield 'rot' => ['#c0392b', Color::LIGHT_TEXT];
        yield 'gelb' => ['#ffbb33', Color::DARK_TEXT];
        yield 'hellgrün' => ['#b8e986', Color::DARK_TEXT];
        yield 'weiß' => ['#fff', Color::DARK_TEXT];
        yield 'schwarz' => ['#000000', Color::LIGHT_TEXT];
        yield 'rgba aus forcal' => ['rgba(135,1,101,.8)', Color::LIGHT_TEXT];
        yield 'unlesbar' => ['kaputt', Color::LIGHT_TEXT];
        yield 'leer' => [null, Color::LIGHT_TEXT];
    }

    #[Test]
    #[DataProvider('colors')]
    public function picksReadableTextColor(?string $background, string $expected): void
    {
        self::assertSame($expected, Color::textOn($background));
    }
}

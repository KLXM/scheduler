<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Tests\Unit;

use KLXM\Scheduler\Backend\Geo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GeoTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?float, ?float, bool}>
     */
    public static function inputs(): iterable
    {
        yield 'Picker-Format' => ['51.5136,7.4653', 51.5136, 7.4653, false];
        yield 'mit Leerzeichen' => ['51.5136, 7.4653', 51.5136, 7.4653, false];
        yield 'Dezimalkomma' => ['51,5136; 7,4653', 51.5136, 7.4653, false];
        yield 'negativ' => ['-33.86 151.21', -33.86, 151.21, false];
        yield 'leer' => ['  ', null, null, false];
        yield 'nur ein Wert' => ['51.5', null, null, true];
        yield 'Text' => ['Dortmund', null, null, true];
        yield 'außerhalb' => ['95,7', null, null, true];
    }

    #[Test]
    #[DataProvider('inputs')]
    public function parsesCoordinates(string $raw, ?float $latitude, ?float $longitude, bool $expectError): void
    {
        [$lat, $lng, $error] = Geo::parse($raw);

        self::assertSame($latitude, $lat);
        self::assertSame($longitude, $lng);
        self::assertSame($expectError, null !== $error);
    }
}

<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Tests\Unit;

use DateTimeZone;
use KLXM\Scheduler\Recurrence\InvalidRuleException;
use KLXM\Scheduler\Recurrence\Rule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RuleTest extends TestCase
{
    #[Test]
    public function parsesAndNormalizesPrefixAndCase(): void
    {
        $rule = Rule::parse('rrule:freq=weekly;interval=2;byday=mo,we');

        self::assertSame('WEEKLY', $rule->frequency);
        self::assertSame(2, $rule->interval);
        self::assertTrue($rule->isInfinite);
        self::assertSame('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE', (string) $rule);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRules(): iterable
    {
        yield 'ohne Frequenz' => ['INTERVAL=2'];
        yield 'unbekannte Frequenz' => ['FREQ=SOMETIMES'];
        yield 'COUNT und UNTIL' => ['FREQ=DAILY;COUNT=3;UNTIL=20261231'];
        yield 'kaputter Bestandteil' => ['FREQ=DAILY;BYDAY'];
        yield 'ungültiger Wochentag' => ['FREQ=WEEKLY;BYDAY=XX'];
    }

    #[Test]
    #[DataProvider('invalidRules')]
    public function rejectsInvalidRules(string $rrule): void
    {
        $this->expectException(InvalidRuleException::class);
        Rule::parse($rrule);
    }

    #[Test]
    public function untilIsDateForAllDayAndUtcForTimedEvents(): void
    {
        $zone = new DateTimeZone('Europe/Berlin');
        $rule = Rule::parse('FREQ=DAILY;UNTIL=20261027');

        self::assertSame('FREQ=DAILY;UNTIL=20261027', (string) $rule->normalizedFor(true, $zone));
        self::assertSame('FREQ=DAILY;UNTIL=20261027T225959Z', (string) $rule->normalizedFor(false, $zone));
    }

    #[Test]
    public function describesRuleInGerman(): void
    {
        $text = Rule::parse('FREQ=WEEKLY;INTERVAL=2;BYDAY=TU')->toText('de');

        self::assertStringContainsStringIgnoringCase('dienstag', $text);
    }
}

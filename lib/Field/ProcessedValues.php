<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Field;

final readonly class ProcessedValues
{
    /**
     * @param array<string, mixed> $values
     * @param array<int, array<string, mixed>> $translatedValues nach Sprach-ID
     * @param array<string, string> $errors Feldname => Meldung
     */
    public function __construct(
        public array $values,
        public array $translatedValues,
        public array $errors,
    ) {}
}

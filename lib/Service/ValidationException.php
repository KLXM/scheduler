<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Service;

final class ValidationException extends \DomainException
{
    /**
     * @param array<string, string> $errors Feldname => Meldung
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct(implode(' ', $errors));
    }
}

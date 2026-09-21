<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Orm\Attribute;

use Attribute;

/**
 * Markiert den automatisch vergebenen Primärschlüssel.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Id {}

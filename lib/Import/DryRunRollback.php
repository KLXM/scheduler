<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Import;

/**
 * Bricht die Transaktion eines Probelaufs ab, nachdem alle Schritte wirklich ausgeführt wurden.
 *
 * @internal
 */
final class DryRunRollback extends \RuntimeException {}

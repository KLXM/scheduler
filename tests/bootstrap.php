<?php

declare(strict_types=1);

// sabre/vobject kommt aus dem Addon dav.
require dirname(__DIR__, 2) . '/dav/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/autoload.php';

// Die Suite "redaxo" braucht ein laufendes REDAXO. SCHEDULER_REDAXO_BOOT zeigt auf eine Datei,
// die den Core bootet (klassisches Layout: redaxo/src/core/boot.php mit gesetztem $REX).
$boot = getenv('SCHEDULER_REDAXO_BOOT');
if (is_string($boot) && '' !== $boot) {
    require $boot;
}

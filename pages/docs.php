<?php

declare(strict_types=1);

use KLXM\Scheduler\Backend\Html;

$files = glob(rex_path::addon('scheduler', 'docs/*.md')) ?: [];
$chapters = [];
foreach ($files as $file) {
    $key = basename($file, '.md');
    $title = trim(ltrim((string) (preg_split('/\R/', (string) rex_file::get($file))[0] ?? $key), '# '));
    $chapters[$key] = ['file' => $file, 'title' => $title];
}

// Redakteure ohne Adminrechte sehen nur, was sie betrifft.
if (!rex::requireUser()->isAdmin()) {
    $chapters = array_intersect_key($chapters, array_flip(['02-termine-pflegen', '06-ics-und-caldav']));
}

$current = rex_request('chapter', 'string');
$current = isset($chapters[$current]) ? $current : (string) array_key_first($chapters);

$nav = '<nav class="scheduler-docs-nav" aria-label="Kapitel"><ul>';
foreach ($chapters as $key => $chapter) {
    $nav .= sprintf(
        '<li><a href="%s"%s>%s</a></li>',
        Html::e(rex_url::currentBackendPage(['chapter' => $key], false)),
        $key === $current ? ' aria-current="page"' : '',
        Html::e($chapter['title']),
    );
}
$nav .= '</ul></nav>';

[$toc, $content] = rex_markdown::factory()->parseWithToc((string) rex_file::get($chapters[$current]['file']), 2, 3, [rex_markdown::SOFT_LINE_BREAKS => false]);

echo '<div class="scheduler-docs">' . $nav . '<article class="scheduler-docs-content">' . $content . '</article></div>';

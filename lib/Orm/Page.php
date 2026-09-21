<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Orm;

/**
 * @template T
 */
final class Page
{
    public readonly int $pages;

    /**
     * @param list<T> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
        $this->pages = max(1, (int) ceil($total / max(1, $perPage)));
    }

    public bool $hasNext {
        get => $this->page < $this->pages;
    }
}

<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Orm;

use DateTimeImmutable;
use KLXM\Scheduler\Orm\Attribute\Column;

trait Timestamps
{
    #[Column]
    public private(set) ?DateTimeImmutable $createdAt = null;

    #[Column]
    public private(set) ?DateTimeImmutable $updatedAt = null;

    #[Column(length: 191)]
    public private(set) ?string $createdBy = null;

    #[Column(length: 191)]
    public private(set) ?string $updatedBy = null;

    public function touch(DateTimeImmutable $now, string $user, bool $isNew): void
    {
        if ($isNew || null === $this->createdAt) {
            $this->createdAt = $now;
            $this->createdBy = $user;
        }
        $this->updatedAt = $now;
        $this->updatedBy = $user;
    }
}

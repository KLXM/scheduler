<?php

declare(strict_types=1);

use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Orm\SchemaManager;

SchemaManager::drop(Scheduler::ENTITIES);

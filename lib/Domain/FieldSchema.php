<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Domain;

use KLXM\Scheduler\Domain\Enum\SchemaTarget;
use KLXM\Scheduler\Orm\Attribute\Column;
use KLXM\Scheduler\Orm\Attribute\Id;
use KLXM\Scheduler\Orm\Attribute\Index;
use KLXM\Scheduler\Orm\Attribute\Table;
use KLXM\Scheduler\Orm\Timestamped;
use KLXM\Scheduler\Orm\Timestamps;

/**
 * Versionierte Custom-Field-Definition aus dem Formbuilder.
 */
#[Table('scheduler_field_schema')]
#[Index('target_version', ['target', 'version'], unique: true)]
class FieldSchema implements Timestamped
{
    use Timestamps;

    #[Id, Column]
    public private(set) ?int $id = null;

    #[Column(length: 32)]
    public SchemaTarget $target = SchemaTarget::Event;

    #[Column]
    public int $version = 1;

    #[Column]
    public bool $active = false;

    /** @var array<string, mixed> Baum aus Struktur- und Feldknoten */
    #[Column]
    public array $definition = [];

    #[Column(length: 500)]
    public ?string $note = null;
}

<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Field;

use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Domain\Calendar;
use KLXM\Scheduler\Domain\Enum\SchemaTarget;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Domain\EventTranslation;
use KLXM\Scheduler\Domain\FieldSchema;
use KLXM\Scheduler\Domain\Location;
use KLXM\Scheduler\Orm\EntityManager;
use KLXM\Scheduler\Orm\MetadataFactory;
use KLXM\Scheduler\Service\ValidationException;

/**
 * Verwaltet die versionierten Custom-Field-Schemata: aktives Schema lesen, neue Version
 * speichern, Werte bei Umbenennungen mitziehen, Indexspalten pflegen, als Datei austauschen.
 */
final class SchemaService
{
    private const string INDEX_PREFIX = 'cf_';

    /** @var array<string, Schema> */
    private array $cache = [];

    public function __construct(
        private readonly EntityManager $manager,
        public readonly TypeRegistry $types,
    ) {}

    public function active(SchemaTarget $target): Schema
    {
        return $this->cache[$target->value] ??= Schema::fromArray($this->activeRecord($target)->definition ?? []);
    }

    public function activeRecord(SchemaTarget $target): ?FieldSchema
    {
        return $this->manager->repository(FieldSchema::class)->query()
            ->where('target', $target)
            ->where('active', true)
            ->orderBy('version', 'desc')
            ->first();
    }

    /**
     * @return list<FieldSchema> neueste zuerst
     */
    public function history(SchemaTarget $target, int $limit = 20): array
    {
        return $this->manager->repository(FieldSchema::class)->query()->where('target', $target)->orderBy('version', 'desc')->limit($limit)->get();
    }

    /**
     * Speichert das Schema als neue aktive Version.
     *
     * @param array<string, string> $renames alter Feldname => neuer Feldname; gespeicherte Werte ziehen mit um
     * @throws ValidationException
     */
    public function publish(SchemaTarget $target, Schema $schema, array $renames = [], ?string $note = null): FieldSchema
    {
        $errors = $schema->validate($this->types, $this->reservedNames($target));
        if ([] !== $errors) {
            throw new ValidationException(array_combine(array_map(static fn (int $i): string => 'schema_' . $i, array_keys($errors)), $errors));
        }

        $record = $this->manager->transactional(function () use ($target, $schema, $renames, $note): FieldSchema {
            $repository = $this->manager->repository(FieldSchema::class);
            $previous = $this->activeRecord($target);
            $previousSchema = Schema::fromArray($previous->definition ?? []);

            foreach ($renames as $from => $to) {
                if ($from !== $to && isset($previousSchema->fields()[$from], $schema->fields()[$to])) {
                    $this->renameValues($target, $from, $to, $previousSchema->fields()[$from]->translatable);
                }
            }

            $this->manager->connection->execute(
                'UPDATE ' . $this->manager->connection->table(MetadataFactory::for(FieldSchema::class)->table) . ' SET `active` = 0 WHERE `target` = ?',
                [$target->value],
            );

            $record = new FieldSchema();
            $record->target = $target;
            $record->version = ($this->history($target, 1)[0]->version ?? 0) + 1;
            $record->active = true;
            $record->definition = $schema->toArray();
            $record->note = $note;
            $repository->save($record);

            return $record;
        });

        unset($this->cache[$target->value]);
        // ALTER TABLE beendet in MySQL und MariaDB jede Transaktion, deshalb erst danach.
        $this->syncIndexColumns($target, $schema);

        return $record;
    }

    /**
     * Wie viele Datensätze haben für das Feld einen Wert? Grundlage für Warnungen im Builder.
     */
    public function usage(SchemaTarget $target, string $field): int
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $field)) {
            return 0;
        }
        $connection = $this->manager->connection;
        $count = 0;
        foreach ($this->valueTables($target) as $table) {
            $count += (int) $connection->fetchAll(
                'SELECT COUNT(*) AS c FROM ' . $connection->table($table) . " WHERE JSON_EXTRACT(`custom`, '$." . $field . "') IS NOT NULL",
            )[0]['c'];
        }

        return $count;
    }

    /**
     * Spaltenname für schnelle Filter, falls das Feld als filterbar markiert ist.
     */
    public function indexColumn(SchemaTarget $target, string $field): ?string
    {
        $node = $this->active($target)->fields()[$field] ?? null;

        return (null !== $node && $node->filterable && !$node->translatable && 'repeater' !== $node->type) ? self::INDEX_PREFIX . $field : null;
    }

    public function exportJson(SchemaTarget $target): string
    {
        return json_encode(
            ['target' => $target->value, ...$this->active($target)->toArray()],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) . "\n";
    }

    /**
     * Spielt eine exportierte Definition ein, sofern sie vom aktiven Schema abweicht.
     *
     * @return bool true, wenn eine neue Version entstanden ist
     */
    public function importJson(string $json, ?string $note = null): bool
    {
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new ValidationException(['schema' => I18n::t('schema_error_invalid_file')]);
        }
        $target = SchemaTarget::tryFrom((string) ($data['target'] ?? '')) ?? throw new ValidationException(['schema' => I18n::t('schema_error_target')]);
        $schema = Schema::fromArray($data);

        if ($schema->toArray() === $this->active($target)->toArray()) {
            return false;
        }
        $this->publish($target, $schema, note: $note ?? I18n::t('fields_note_import'));

        return true;
    }

    /**
     * @return list<string>
     */
    public function reservedNames(SchemaTarget $target): array
    {
        $names = [];
        foreach ($this->entities($target) as $entity) {
            foreach (MetadataFactory::for($entity)->columns as $column) {
                array_push($names, $column->column, strtolower($column->property));
            }
        }

        return array_values(array_unique([...$names, 'title', 'teaser', 'description', 'start', 'end', 'location', 'calendar']));
    }

    /**
     * Legt für filterbare Felder virtuelle, indexierte Spalten an und entfernt nicht mehr benötigte.
     */
    private function syncIndexColumns(SchemaTarget $target, Schema $schema): void
    {
        $connection = $this->manager->connection;
        $table = $connection->table(MetadataFactory::for($this->entities($target)[0])->table);

        $wanted = [];
        foreach ($schema->fields() as $name => $node) {
            if ($node->filterable && !$node->translatable && 'repeater' !== $node->type) {
                $wanted[self::INDEX_PREFIX . $name] = $name;
            }
        }

        $existing = [];
        foreach ($connection->fetchAll('SHOW COLUMNS FROM ' . $table . " LIKE 'cf\\_%'") as $column) {
            $existing[] = (string) $column['Field'];
        }

        foreach (array_diff($existing, array_keys($wanted)) as $column) {
            $connection->execute('ALTER TABLE ' . $table . ' DROP COLUMN ' . $connection->quoteIdentifier($column));
        }
        foreach (array_diff(array_keys($wanted), $existing) as $column) {
            $connection->execute(sprintf(
                "ALTER TABLE %s ADD COLUMN %s VARCHAR(191) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`custom`, '$.%s'))) VIRTUAL, ADD INDEX %s (%s)",
                $table,
                $connection->quoteIdentifier($column),
                $wanted[$column],
                $connection->quoteIdentifier($column),
                $connection->quoteIdentifier($column),
            ));
        }
    }

    private function renameValues(SchemaTarget $target, string $from, string $to, bool $translatable): void
    {
        $connection = $this->manager->connection;
        $tables = $translatable && SchemaTarget::Event === $target
            ? [MetadataFactory::for(EventTranslation::class)->table]
            : [MetadataFactory::for($this->entities($target)[0])->table];

        foreach ($tables as $table) {
            $connection->execute(sprintf(
                "UPDATE %s SET `custom` = JSON_REMOVE(JSON_SET(`custom`, '$.%s', JSON_EXTRACT(`custom`, '$.%s')), '$.%s') WHERE JSON_EXTRACT(`custom`, '$.%s') IS NOT NULL",
                $connection->table($table),
                $to,
                $from,
                $from,
                $from,
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function valueTables(SchemaTarget $target): array
    {
        return array_map(static fn (string $entity): string => MetadataFactory::for($entity)->table, $this->entities($target));
    }

    /**
     * @return non-empty-list<class-string>
     */
    private function entities(SchemaTarget $target): array
    {
        return match ($target) {
            SchemaTarget::Event => [Event::class, EventTranslation::class],
            SchemaTarget::Calendar => [Calendar::class],
            SchemaTarget::Location => [Location::class],
        };
    }
}

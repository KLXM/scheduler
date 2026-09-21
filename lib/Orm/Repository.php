<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Orm;

use DateTimeImmutable;
use DateTimeZone;

/**
 * @template T of object
 */
class Repository
{
    /** @var EntityMetadata<T> */
    public readonly EntityMetadata $metadata;

    /**
     * @param class-string<T> $class
     */
    public function __construct(
        protected readonly EntityManager $manager,
        string $class,
    ) {
        $this->metadata = MetadataFactory::for($class);
    }

    /**
     * @return Query<T>
     */
    public function query(): Query
    {
        return new Query($this->manager->connection, $this->metadata, $this->hydrateRows(...));
    }

    /**
     * @return T|null
     */
    public function find(int $id): ?object
    {
        return $this->query()->where($this->metadata->id->property, $id)->first();
    }

    /**
     * @return T
     */
    public function findOrFail(int $id): object
    {
        return $this->find($id)
            ?? throw new OrmException(sprintf('%s #%d wurde nicht gefunden.', $this->metadata->class, $id));
    }

    /**
     * @param list<int> $ids
     * @return array<int, T> nach ID
     */
    public function findMany(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        $result = [];
        foreach ($this->query()->whereIn($this->metadata->id->property, $ids)->get() as $entity) {
            $result[$this->idOf($entity)] = $entity;
        }

        return $result;
    }

    /**
     * @param T $entity
     */
    public function save(object $entity): void
    {
        $idColumn = $this->metadata->id;
        $id = $idColumn->reflection->isInitialized($entity) ? $idColumn->reflection->getValue($entity) : null;
        $isNew = null === $id;

        if ($entity instanceof Timestamped) {
            $entity->touch(new DateTimeImmutable('now', new DateTimeZone('UTC')), $this->manager->currentUser(), $isNew);
        }

        $this->beforeSave($entity, $isNew);
        $data = Hydrator::extract($this->metadata, $entity);
        $connection = $this->manager->connection;
        $columns = array_map($connection->quoteIdentifier(...), array_keys($data));

        if ($isNew) {
            $connection->execute(
                sprintf(
                    'INSERT INTO %s (%s) VALUES (%s)',
                    $connection->table($this->metadata->table),
                    implode(', ', $columns),
                    implode(', ', array_fill(0, count($data), '?')),
                ),
                array_values($data),
            );
            $idColumn->reflection->setValue($entity, $connection->lastInsertId());
        } else {
            $connection->execute(
                sprintf(
                    'UPDATE %s SET %s WHERE %s = ?',
                    $connection->table($this->metadata->table),
                    implode(', ', array_map(static fn (string $c): string => $c . ' = ?', $columns)),
                    $connection->quoteIdentifier($idColumn->column),
                ),
                [...array_values($data), (int) $id],
            );
        }

        $this->afterSave($entity, $isNew);
    }

    /**
     * Legt eine Entity mit vorgegebenem Primärschlüssel an (für Importe, die IDs erhalten).
     *
     * @param T $entity
     */
    public function insertWithId(object $entity, int $id): void
    {
        if ($entity instanceof Timestamped) {
            $entity->touch(new DateTimeImmutable('now', new DateTimeZone('UTC')), $this->manager->currentUser(), true);
        }

        $this->beforeSave($entity, true);
        $connection = $this->manager->connection;
        $data = [$this->metadata->id->column => $id, ...Hydrator::extract($this->metadata, $entity)];
        $connection->execute(
            sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $connection->table($this->metadata->table),
                implode(', ', array_map($connection->quoteIdentifier(...), array_keys($data))),
                implode(', ', array_fill(0, count($data), '?')),
            ),
            array_values($data),
        );
        $this->metadata->id->reflection->setValue($entity, $id);
        $this->afterSave($entity, true);
    }

    /**
     * @param T $entity
     */
    public function delete(object $entity): void
    {
        $id = $this->idOf($entity);
        $this->beforeDelete($entity);
        $this->manager->connection->execute(
            sprintf(
                'DELETE FROM %s WHERE %s = ?',
                $this->manager->connection->table($this->metadata->table),
                $this->manager->connection->quoteIdentifier($this->metadata->id->column),
            ),
            [$id],
        );
        $this->afterDelete($entity, $id);
    }

    /**
     * @param T $entity
     */
    public function idOf(object $entity): int
    {
        $reflection = $this->metadata->id->reflection;
        $id = $reflection->isInitialized($entity) ? $reflection->getValue($entity) : null;

        return is_int($id) ? $id : throw new OrmException(sprintf('%s ist noch nicht gespeichert.', $this->metadata->class));
    }

    /**
     * @param list<array<string, scalar|null>> $rows
     * @return list<T>
     */
    protected function hydrateRows(array $rows): array
    {
        $entities = array_map(fn (array $row): object => Hydrator::hydrate($this->metadata, $row), $rows);
        $this->afterLoad($entities);

        return $entities;
    }

    /**
     * Hook für abgeleitete Repositories, etwa um Relationen gesammelt nachzuladen.
     *
     * @param list<T> $entities
     */
    protected function afterLoad(array $entities): void {}

    /** @param T $entity */
    protected function beforeSave(object $entity, bool $isNew): void {}

    /** @param T $entity */
    protected function afterSave(object $entity, bool $isNew): void {}

    /** @param T $entity */
    protected function beforeDelete(object $entity): void {}

    /** @param T $entity */
    protected function afterDelete(object $entity, int $id): void {}
}

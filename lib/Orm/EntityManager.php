<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Orm;

use Closure;

final class EntityManager
{
    /** @var array<class-string, Repository<object>> */
    private array $repositories = [];

    /** @var array<class-string, class-string<Repository<*>>> */
    private array $repositoryClasses = [];

    /**
     * @param Closure(): string $userResolver liefert den Login für Erstell- und Änderungsvermerke
     */
    public function __construct(
        public readonly Connection $connection,
        private readonly Closure $userResolver,
    ) {}

    /**
     * @param class-string $entity
     * @param class-string<Repository<*>> $repository
     */
    public function register(string $entity, string $repository): void
    {
        $this->repositoryClasses[$entity] = $repository;
    }

    /**
     * @template T of object
     * @param class-string<T> $entity
     * @return Repository<T>
     */
    public function repository(string $entity): Repository
    {
        if (!isset($this->repositories[$entity])) {
            $class = $this->repositoryClasses[$entity] ?? Repository::class;
            $this->repositories[$entity] = new $class($this, $entity);
        }

        /** @var Repository<T> */
        return $this->repositories[$entity];
    }

    public function currentUser(): string
    {
        return ($this->userResolver)();
    }

    /**
     * @template R
     * @param callable(): R $callback
     * @return R
     */
    public function transactional(callable $callback): mixed
    {
        return $this->connection->transactional($callback);
    }
}

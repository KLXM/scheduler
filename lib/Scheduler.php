<?php

declare(strict_types=1);

namespace KLXM\Scheduler;

use KLXM\Scheduler\Domain\Calendar;
use KLXM\Scheduler\Domain\DavChange;
use KLXM\Scheduler\Domain\Enum\SchemaTarget;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Domain\EventOverride;
use KLXM\Scheduler\Domain\EventTranslation;
use KLXM\Scheduler\Domain\FieldSchema;
use KLXM\Scheduler\Domain\Location;
use KLXM\Scheduler\Domain\Occurrence;
use KLXM\Scheduler\Domain\Subscription;
use KLXM\Scheduler\Field\SchemaService;
use KLXM\Scheduler\Field\TypeRegistry;
use KLXM\Scheduler\Orm\Connection;
use KLXM\Scheduler\Orm\EntityManager;
use KLXM\Scheduler\Orm\Repository;
use KLXM\Scheduler\Orm\RexSqlConnection;
use KLXM\Scheduler\Query\OccurrenceQuery;
use KLXM\Scheduler\Recurrence\Expander;
use KLXM\Scheduler\Repository\CalendarRepository;
use KLXM\Scheduler\Repository\EventRepository;
use KLXM\Scheduler\Service\EventValidator;
use KLXM\Scheduler\Service\OccurrenceIndexer;

/**
 * Einstiegspunkt in die scheduler-API.
 *
 *     $next = Scheduler::occurrences()->upcoming()->inCalendars(3)->limit(5)->get();
 *     $event = Scheduler::events()->findOrFail(42);
 */
final class Scheduler
{
    /** Alle persistenten Entities; daraus entsteht das Datenbankschema. */
    public const array ENTITIES = [
        Calendar::class,
        Location::class,
        Event::class,
        EventTranslation::class,
        EventOverride::class,
        Occurrence::class,
        FieldSchema::class,
        DavChange::class,
        Subscription::class,
    ];

    private static ?EntityManager $manager = null;
    private static ?Settings $settings = null;
    private static ?OccurrenceIndexer $indexer = null;
    private static ?SchemaService $schemas = null;

    public static function em(): EntityManager
    {
        if (null === self::$manager) {
            self::boot(new RexSqlConnection(), Settings::fromRedaxo(), static fn (): string => \rex::getUser()?->getLogin() ?? \rex::getEnvironment());
        }

        return self::$manager ?? throw new \LogicException('scheduler ist nicht initialisiert.');
    }

    /**
     * Initialisiert scheduler mit eigener Verbindung, etwa in Tests.
     *
     * @param \Closure(): string $userResolver
     */
    public static function boot(Connection $connection, Settings $settings, \Closure $userResolver): void
    {
        self::$manager = new EntityManager($connection, $userResolver);
        self::$manager->register(Event::class, EventRepository::class);
        self::$manager->register(Calendar::class, CalendarRepository::class);
        self::$settings = $settings;
        self::$indexer = null;
        self::$schemas = null;
    }

    public static function settings(): Settings
    {
        return self::$settings ??= Settings::fromRedaxo();
    }

    public static function events(): EventRepository
    {
        /** @var EventRepository */
        return self::em()->repository(Event::class);
    }

    public static function calendars(): CalendarRepository
    {
        /** @var CalendarRepository */
        return self::em()->repository(Calendar::class);
    }

    /**
     * @return Repository<Location>
     */
    public static function locations(): Repository
    {
        return self::em()->repository(Location::class);
    }

    public static function occurrences(): OccurrenceQuery
    {
        return new OccurrenceQuery(
            self::em(),
            self::settings()->defaultTimezone(),
            static fn (string $field): ?string => self::schemas()->indexColumn(SchemaTarget::Event, $field),
        );
    }

    public static function indexer(): OccurrenceIndexer
    {
        return self::$indexer ??= new OccurrenceIndexer(self::em(), self::settings());
    }

    /**
     * Custom-Field-Schemata. Eigene Feldtypen: Scheduler::schemas()->types->register(new MyType()).
     */
    public static function schemas(): SchemaService
    {
        return self::$schemas ??= new SchemaService(self::em(), TypeRegistry::withDefaults());
    }

    public static function expander(): Expander
    {
        return new Expander();
    }

    public static function validator(): EventValidator
    {
        return new EventValidator();
    }
}

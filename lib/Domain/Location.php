<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Domain;

use KLXM\Scheduler\Orm\Attribute\Column;
use KLXM\Scheduler\Orm\Attribute\Id;
use KLXM\Scheduler\Orm\Attribute\Table;
use KLXM\Scheduler\Orm\Timestamped;
use KLXM\Scheduler\Orm\Timestamps;

#[Table('scheduler_location')]
class Location implements Timestamped
{
    use Timestamps;

    #[Id, Column]
    public private(set) ?int $id = null;

    #[Column]
    public string $name = '';

    #[Column]
    public ?string $street = null;

    #[Column(length: 20)]
    public ?string $zip = null;

    #[Column]
    public ?string $city = null;

    #[Column(length: 100)]
    public ?string $country = null;

    #[Column]
    public ?float $latitude = null;

    #[Column]
    public ?float $longitude = null;

    #[Column(length: 500)]
    public ?string $url = null;

    #[Column]
    public bool $active = true;

    /** @var array<string, mixed> */
    #[Column]
    public array $custom = [];

    /** Einzeilige Adresse für die iCalendar-Eigenschaft LOCATION. */
    public string $label {
        get {
            $cityLine = trim(($this->zip ?? '') . ' ' . ($this->city ?? ''));
            $parts = array_filter([$this->name, $this->street, $cityLine, $this->country], static fn (?string $p): bool => null !== $p && '' !== $p);

            return implode(', ', $parts);
        }
    }

    public bool $hasGeo {
        get => null !== $this->latitude && null !== $this->longitude;
    }
}

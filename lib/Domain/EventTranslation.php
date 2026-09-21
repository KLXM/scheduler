<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Domain;

use KLXM\Scheduler\Orm\Attribute\Column;
use KLXM\Scheduler\Orm\Attribute\Id;
use KLXM\Scheduler\Orm\Attribute\Index;
use KLXM\Scheduler\Orm\Attribute\Table;
use KLXM\Scheduler\Orm\ColumnType;

#[Table('scheduler_event_lang')]
#[Index('event_clang', ['event_id', 'clang_id'], unique: true)]
class EventTranslation
{
    #[Id, Column]
    public private(set) ?int $id = null;

    #[Column]
    public int $eventId = 0;

    #[Column]
    public int $clangId = 1;

    #[Column(length: 500)]
    public string $title = '';

    #[Column(type: ColumnType::Text)]
    public ?string $teaser = null;

    #[Column(type: ColumnType::LongText)]
    public ?string $description = null;

    /** @var array<string, mixed> Werte der als übersetzbar markierten Custom Fields */
    #[Column]
    public array $custom = [];

    public function __construct(int $clangId = 1, string $title = '')
    {
        $this->clangId = $clangId;
        $this->title = $title;
    }

    /** Reiner Text für die iCalendar-Eigenschaft DESCRIPTION. */
    public string $plainDescription {
        get {
            $source = (null !== $this->teaser && '' !== trim($this->teaser)) ? $this->teaser : (string) $this->description;
            $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $source)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
        }
    }
}

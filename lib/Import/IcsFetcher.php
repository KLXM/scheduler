<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Import;

use KLXM\Scheduler\I18n;
use rex_socket;

/**
 * Lädt iCalendar-Daten von einer URL. Folgt Weiterleitungen und meldet verständlich,
 * wenn die Gegenseite etwas anderes als einen Kalender liefert.
 */
final class IcsFetcher
{
    private const int MAX_REDIRECTS = 5;

    /**
     * @throws FetchException
     */
    public function fetch(string $url): string
    {
        $url = trim($url);
        // Abo-Adressen werden oft als webcal:// verteilt; abgerufen werden sie über https.
        $url = (string) preg_replace('#^webcals?://#i', 'https://', $url);
        if (1 !== preg_match('#^https?://#i', $url)) {
            throw new FetchException(I18n::t('fetch_scheme'));
        }

        try {
            $response = rex_socket::factoryUrl($url)
                ->followRedirects(self::MAX_REDIRECTS)
                ->addHeader('Accept', 'text/calendar, text/plain;q=0.8, */*;q=0.5')
                ->addHeader('User-Agent', 'REDAXO scheduler')
                ->setTimeout(20)
                ->doGet();
        } catch (\Throwable $e) {
            throw new FetchException(I18n::t('fetch_unreachable', $e->getMessage()), previous: $e);
        }

        if (!$response->isOk()) {
            throw new FetchException(I18n::t('fetch_status', $response->getStatusCode()));
        }

        $body = $response->getBody();
        if (!str_contains($body, 'BEGIN:VCALENDAR')) {
            throw new FetchException(I18n::t('fetch_no_calendar'));
        }

        return $body;
    }
}

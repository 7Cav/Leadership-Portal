<?php

namespace App\Sync\Calendar;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;

/**
 * Reads the events out of an iCalendar file (RFC 5545), as far as the 7Cav calendar needs (#751): each `VEVENT`'s
 * `UID`, `SUMMARY`, `DTSTART`, `DTEND`, `URL`, `STATUS`, `RRULE` and `EXDATE`. A time is read in UTC (`...Z`), in the
 * zone its `TZID` names, or as UTC when it names none; a date alone is the start of that day. Pure: no I/O, no clock.
 *
 * Lines are unfolded as the standard says: a line that starts with a space or a tab continues the one before. The
 * forum's export also writes an event's description with its line breaks as they are, unfolded, so a line inside an
 * event that does not start with a property name the standard knows (`DESCRIPTION:`, `SUMMARY;...:`, an `X-` name) is
 * read as more of the property before it. The export writes the description before the start and the title, and a
 * property given twice keeps its last value, so a description line that happens to read like a property cannot take
 * the event's real one.
 *
 * An event is left out when it has no id, no title or no start it can read, or is cancelled.
 */
final class ICalendar
{
    /** The property names a line inside an event may start with; anything else continues the line before. */
    private const PROPERTIES = [
        'BEGIN', 'END', 'UID', 'DTSTAMP', 'DTSTART', 'DTEND', 'DURATION', 'SUMMARY', 'DESCRIPTION', 'LOCATION', 'URL',
        'RRULE', 'RDATE', 'EXDATE', 'EXRULE', 'RECURRENCE-ID', 'STATUS', 'CATEGORIES', 'PARTICIPATION', 'SEQUENCE',
        'CREATED', 'LAST-MODIFIED', 'ORGANIZER', 'ATTENDEE', 'CLASS', 'TRANSP', 'PRIORITY', 'GEO', 'CONTACT', 'COMMENT',
        'RESOURCES', 'RELATED-TO', 'ATTACH', 'REQUEST-STATUS', 'COLOR', 'IMAGE', 'CONFERENCE',
    ];

    /** A content line: its name, its parameters (`;TZID=...`) and, after the first colon outside quotes, its value. */
    private const LINE = '/^([A-Za-z0-9-]+)((?:;[A-Za-z0-9-]+=(?:"[^"]*"|[^:;"])*)*):(.*)$/s';

    /**
     * @return list<CalendarEntry>
     */
    public static function parse(string $source): array
    {
        $entries = [];
        /** @var array<string, list<array{params: array<string, string>, value: string}>>|null $event */
        $event = null;
        $depth = 0;
        $last = null;

        foreach (self::unfold($source) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $matched = preg_match(self::LINE, $line, $match) === 1;
            $property = $matched ? strtoupper($match[1]) : null;

            if ($event === null) {
                if ($property === 'BEGIN' && strtoupper(trim($match[3])) === 'VEVENT') {
                    $event = [];
                    $depth = 0;
                    $last = null;
                }

                continue;
            }

            $known = $property !== null && (in_array($property, self::PROPERTIES, true) || str_starts_with($property, 'X-'));

            if (! $known) {
                // More of the property before (the export's raw line breaks), kept only for a property of the event itself.
                if ($depth === 0 && $last !== null) {
                    $index = array_key_last($event[$last]);
                    $event[$last][$index]['value'] .= "\n".$line;
                }

                continue;
            }

            if ($property === 'BEGIN') {
                // An alarm or other part inside the event: its lines are not the event's.
                $depth++;
                $last = null;

                continue;
            }

            if ($property === 'END') {
                if ($depth > 0) {
                    $depth--;

                    continue;
                }

                $entry = self::entry($event);
                if ($entry !== null) {
                    $entries[] = $entry;
                }

                $event = null;

                continue;
            }

            if ($depth > 0) {
                continue;
            }

            $event[$property][] = ['params' => self::params($match[2]), 'value' => $match[3]];
            $last = $property;
        }

        return $entries;
    }

    /**
     * A time or date value as iCalendar writes it (`20261121T190000Z`, `20261121T140000` with a `TZID`, `20261121`), in
     * the zone it was written in. Null when it is not one.
     *
     * @param  array<string, string>  $params
     */
    public static function time(string $value, array $params = []): ?CarbonImmutable
    {
        $value = strtoupper(trim($value));
        $zone = self::zone($params['TZID'] ?? null);

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $date) === 1) {
            return checkdate((int) $date[2], (int) $date[3], (int) $date[1])
                ? CarbonImmutable::create((int) $date[1], (int) $date[2], (int) $date[3], 0, 0, 0, $zone)
                : null;
        }

        if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)$/', $value, $time) !== 1) {
            return null;
        }

        if (! checkdate((int) $time[2], (int) $time[3], (int) $time[1]) || (int) $time[4] > 23 || (int) $time[5] > 59 || (int) $time[6] > 60) {
            return null;
        }

        return CarbonImmutable::create(
            (int) $time[1], (int) $time[2], (int) $time[3], (int) $time[4], (int) $time[5], min(59, (int) $time[6]),
            $time[7] === 'Z' ? new DateTimeZone('UTC') : $zone,
        );
    }

    /**
     * The lines of the file with every folded line joined back to the one it continues.
     *
     * @return list<string>
     */
    private static function unfold(string $source): array
    {
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        // A byte-order mark before BEGIN:VCALENDAR would hide the first line's name.
        if (str_starts_with($source, "\xEF\xBB\xBF")) {
            $source = substr($source, 3);
        }

        $lines = [];
        foreach (explode("\n", $source) as $line) {
            if ($lines !== [] && $line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                $lines[array_key_last($lines)] .= substr($line, 1);

                continue;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * A line's parameters by name: `;TZID=America/New_York;VALUE=DATE-TIME`, quotes taken off.
     *
     * @return array<string, string>
     */
    private static function params(string $raw): array
    {
        $params = [];

        if (preg_match_all('/;([A-Za-z0-9-]+)=("[^"]*"|[^;]*)/', $raw, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        foreach ($matches as $match) {
            $params[strtoupper($match[1])] = trim($match[2], '"');
        }

        return $params;
    }

    /**
     * The zone a `TZID` names, or UTC for none or one PHP does not know (an Outlook name like "Eastern Standard Time").
     */
    private static function zone(?string $tzid): DateTimeZone
    {
        if ($tzid !== null && trim($tzid) !== '') {
            try {
                return new DateTimeZone(trim($tzid));
            } catch (Exception) {
                // Not a zone PHP knows: read as UTC, below.
            }
        }

        return new DateTimeZone('UTC');
    }

    /**
     * @param  array<string, list<array{params: array<string, string>, value: string}>>  $event
     */
    private static function entry(array $event): ?CalendarEntry
    {
        $one = fn (string $name): ?array => isset($event[$name]) ? $event[$name][array_key_last($event[$name])] : null;

        $uid = trim(self::text($one('UID')['value'] ?? ''));
        $title = trim(preg_replace('/\s+/u', ' ', self::text($one('SUMMARY')['value'] ?? '')) ?? '');
        $startLine = $one('DTSTART');
        $start = $startLine === null ? null : self::time($startLine['value'], $startLine['params']);

        if ($uid === '' || $title === '' || $start === null || strtoupper(trim($one('STATUS')['value'] ?? '')) === 'CANCELLED') {
            return null;
        }

        $endLine = $one('DTEND');
        $end = $endLine === null ? null : self::time($endLine['value'], $endLine['params']);
        // An end before its start is not an end.
        $end = $end !== null && $end->greaterThan($start) ? $end : null;

        $url = trim($one('URL')['value'] ?? '');
        $url = preg_match('#^https?://\S+$#i', $url) === 1 ? $url : null;

        // UNTIL and EXDATE are read in the start's own zone when they carry no Z or TZID of their own.
        $zoned = ['TZID' => $start->getTimezone()->getName()];
        $rule = $one('RRULE');
        $recurrence = $rule === null ? null : Recurrence::fromRule(trim($rule['value']), fn (string $value) => self::time($value, $zoned));

        $exceptions = [];
        foreach ($event['EXDATE'] ?? [] as $line) {
            $params = $line['params'] + $zoned;
            foreach (explode(',', $line['value']) as $value) {
                $skipped = self::time($value, $params);
                if ($skipped !== null) {
                    $exceptions[] = $skipped;
                }
            }
        }

        return new CalendarEntry($uid, $title, $start, $end, $url, $recurrence, $exceptions);
    }

    /** A TEXT value with its escapes undone: `\,` `\;` `\\`, and `\n` for a line break. */
    private static function text(string $value): string
    {
        return preg_replace_callback('/\\\\([\\\\,;nN])/', fn (array $m) => in_array($m[1], ['n', 'N'], true) ? "\n" : $m[1], $value) ?? $value;
    }
}

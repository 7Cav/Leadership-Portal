<?php

namespace App\Sync\Loa;

use App\Models\LoaType;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Reads one leave thread: its title and the first post's BBCode. Pure: no database, no forum, and it
 * never guesses. Anything it cannot read cleanly adds a reason, and a thread with a reason asserts no
 * dates (AD-4, the `needs_review` fail-safe).
 *
 * The formats are the real ones on the 7Cav forum (from live threads):
 *  - Bold, gold-coloured labels (`[B][COLOR=...]Start Date[/COLOR][/B]`) whose value is on the same line
 *    or the next, with the colon in varying places. Formatting tags are stripped before reading.
 *  - The `Username` of an LOA is a forum mention, `[USER=12563]Name[/USER]`, which carries the forum user
 *    id. An ELOA post has two `Username` labels, the submitter first, so the subject is the LAST one
 *    (the bot's ADR 0010). An LOA has one; the submitter is under `Submitter Name`.
 *  - The title names the trooper as well, after the tag (`[LOA Request] - PVT.Smith.J | ...`). When it names
 *    someone other than the `Username`, the thread is not read cleanly.
 *  - Dates read `Oct 6, 2026`, `October 6, 2026`, `Oct 6 2026` or `October 6 2026`. A date that is not
 *    a real calendar day (`Feb 30`) is not accepted, rather than rolled over.
 */
final class LoaPostParser
{
    /** @var list<string> */
    private const DATE_FORMATS = ['M j, Y', 'F j, Y', 'M j Y', 'F j Y'];

    /**
     * Every label the forms use. A blank field is followed by the next label, which must never be read as
     * its value.
     *
     * @var list<string>
     */
    private const LABELS = [
        'Username', 'Rank', 'Primary Billet', 'Reason', 'Start Date', 'End Date', 'Request for Self',
        'Submitter Name', 'Type', 'Expected return date', 'Report Statement', 'Specify Section', 'MILPAC', 'PAF LINK',
    ];

    /** A date further than this from today is more likely a typo than leave. */
    private const SANE_DAYS = 1095;

    /** Days from an LOA's Start Date to its End Date: one Cav week, Saturday to Friday, is six. */
    private const SHORTEST_LOA_GAP = 6;

    /** Days from an LOA's Start Date to its End Date when the End Date is the day back: 14 days of leave. */
    private const LONGEST_LOA_GAP = 14;

    public function parse(string $title, string $body, LoaType $section, CarbonImmutable $now): ParsedLoaThread
    {
        $reasons = [];
        $kind = LoaThreadKind::fromTitle($title);

        if ($kind === LoaThreadKind::Unknown) {
            $reasons[] = 'The title does not start with [LOA Request], [ELOA Report] or [ELOA Return].';
        } elseif ($kind->section() !== $section) {
            $reasons[] = "The title says {$kind->label()} but the thread is in the {$section->label()} section.";
        }

        $text = $this->clean($body);

        [$username, $forumUserId] = $this->subject($text, $reasons);

        if ($kind !== LoaThreadKind::Unknown && $username !== null) {
            $this->checkTitleName($title, $username, $reasons);
        }

        $startsAt = $endsAt = $rawStart = $rawEnd = $expectedReturnAt = $pafThreadId = null;

        if ($kind === LoaThreadKind::LoaRequest) {
            [$rawStart, $startsAt] = $this->dateField($text, 'Start Date', $reasons);
            [$rawEnd, $endsAt] = $this->dateField($text, 'End Date', $reasons);
            $this->checkDates($title, $startsAt, $endsAt, $now, $reasons);
        } elseif ($kind === LoaThreadKind::EloaReport || $kind === LoaThreadKind::EloaReturn) {
            $rawEnd = $this->fieldValues($text, 'Expected return date')[0] ?? null;
            $expectedReturnAt = $kind === LoaThreadKind::EloaReport && $rawEnd !== null ? $this->parseDate($rawEnd) : null;

            // Only displayed, so a date that is far off (a typo) is dropped, not shown and not a reason to review.
            if ($expectedReturnAt !== null && abs($expectedReturnAt->diffInDays($now)) > self::SANE_DAYS) {
                $expectedReturnAt = null;
            }

            $pafThreadId = $this->pafThreadId($text);
            $this->checkBodyType($kind, $text, $reasons);
        }

        $review = $reasons === [] ? [null, null] : $this->reviewWindow($startsAt, $endsAt, $now);

        return new ParsedLoaThread(
            kind: $kind,
            username: $username,
            forumUserId: $forumUserId,
            startsAt: $reasons === [] ? $startsAt : null,
            endsAt: $reasons === [] ? $endsAt : null,
            rawStart: $rawStart,
            rawEnd: $rawEnd,
            expectedReturnAt: $expectedReturnAt,
            pafThreadId: $pafThreadId,
            excerpt: $this->excerpt($username, $forumUserId, $rawStart, $rawEnd, $text),
            reasons: $reasons,
            reviewStartsAt: $review[0],
            reviewEndsAt: $review[1],
        );
    }

    /**
     * The leave dates of a post that needs review, when they are usable as a window: both read, the end not before
     * the start, both within three years of today, and the end cut to the longest an LOA runs. Only used to place the
     * needs-review shield on the weeks the trooper said they would be away; they assert no leave.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function reviewWindow(?CarbonImmutable $start, ?CarbonImmutable $end, CarbonImmutable $now): array
    {
        if ($start === null || $end === null || $end->lessThan($start)
            || abs($start->diffInDays($now)) > self::SANE_DAYS || abs($end->diffInDays($now)) > self::SANE_DAYS) {
            return [null, null];
        }

        return [$start, $end->min($start->addDays(self::LONGEST_LOA_GAP))];
    }

    /**
     * Formatting-only BBCode comes out, a link keeps its text, and line endings are made uniform. The
     * `[USER=id]` mention stays, since the subject's forum id is read from it.
     */
    private function clean(string $body): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $body);
        $text = preg_replace('~\[/?(?:b|i|u|s|color|size|font|hr)(?:=[^\]]*)?\]~i', '', $text) ?? $text;

        return preg_replace('~\[URL(?:=[^\]]*)?\](.*?)\[/URL\]~is', '$1', $text) ?? $text;
    }

    /**
     * Every value a label has, in order. The value is on the label's line, or on the next non-empty one.
     *
     * @return list<string>
     */
    private function fieldValues(string $text, string $label): array
    {
        $lines = explode("\n", $text);
        $pattern = '~^\s*'.preg_quote($label, '~').'(?![A-Za-z])\s*:?\s*(.*)$~i';
        $values = [];

        foreach ($lines as $index => $line) {
            if (preg_match($pattern, $line, $match) !== 1) {
                continue;
            }

            $value = trim($match[1]);

            for ($next = $index + 1; $value === '' && $next < count($lines); $next++) {
                $candidate = trim($lines[$next]);

                // A blank field runs straight into the next label; that label is not its value.
                if ($candidate !== '' && $this->startsWithLabel($candidate)) {
                    break;
                }

                $value = $candidate;
            }

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function startsWithLabel(string $line): bool
    {
        $labels = implode('|', array_map(fn (string $label) => preg_quote($label, '~'), self::LABELS));

        return preg_match('~^(?:'.$labels.')(?![A-Za-z])~i', $line) === 1;
    }

    /**
     * The person the leave is for: the last `Username`. One label is a normal LOA, two an ELOA filed on
     * someone's behalf; any more is not a shape we know, so it is not guessed at.
     *
     * @param  list<string>  $reasons
     * @return array{0: ?string, 1: ?int} username, forum user id
     */
    private function subject(string $text, array &$reasons): array
    {
        $values = $this->fieldValues($text, 'Username');

        if ($values === []) {
            $reasons[] = 'There is no Username field.';

            return [null, null];
        }

        if (count($values) > 2) {
            $reasons[] = 'There are '.count($values).' Username fields, so it is unclear who the leave is for.';

            return [null, null];
        }

        $value = end($values);
        $forumUserId = null;

        if (preg_match('~^\[USER=(\d+)\](.*?)\[/USER\]~i', $value, $mention) === 1) {
            $forumUserId = (int) $mention[1];
            $value = $mention[2];
        }

        $name = ltrim(preg_split('/\s+/', trim($value))[0] ?? '', '@');

        if ($name === '') {
            $reasons[] = 'The Username field is empty.';

            return [null, $forumUserId];
        }

        return [$name, $forumUserId];
    }

    /**
     * The title names the trooper too (`[LOA Request] - PVT.Smith.J | ...`, `[ELOA Report] - PFC.Lane.M`), and
     * it must be the same person as the post's `Username`. They are filled in from the same form, so two
     * different names mean the post was edited afterwards, or written to put leave on someone else: which
     * one the leave is for is not for us to decide, so the thread goes to a person.
     *
     * Only the name part is compared, ignoring case: the rank in front of it (`PVT.`) is dropped, and spaces
     * around the dots, or one in place of the dot after the rank, are tolerated. A title with no name in the
     * forum's `Name.Initial` shape (none at all, or free text) is not read as a name and not compared: the
     * title is a second witness here, never the source of who the leave is for.
     *
     * @param  list<string>  $reasons
     */
    private function checkTitleName(string $title, string $username, array &$reasons): void
    {
        if (preg_match('/^\s*\[[^\]]*\]\s*-?\s*([^|]*)/', $title, $match) !== 1) {
            return;
        }

        // `PVT. Smith . J` and `PVT Smith.J` are both `PVT.Smith.J`; any other space means free text, not a name.
        $name = ltrim(preg_replace('/\s*\.\s*/', '.', trim($match[1])) ?? '', '@');
        $name = preg_replace('/^([A-Za-z0-9]{1,5})\s+(?=\S+$)/', '$1.', $name) ?? $name;

        if (preg_match("/^[\\w'-]+(?:\\.[\\w'-]+)+$/u", $name) !== 1) {
            return;
        }

        // With the rank, or without the first segment, which is the rank when there is one.
        $withoutRank = substr($name, strpos($name, '.') + 1);
        $wanted = mb_strtolower($username);

        if ($wanted !== mb_strtolower($name) && $wanted !== mb_strtolower($withoutRank)) {
            $reasons[] = 'The title names '.mb_substr($name, 0, 40).' but the post names '.mb_substr($username, 0, 40).'.';
        }
    }

    /**
     * @param  list<string>  $reasons
     * @return array{0: ?string, 1: ?CarbonImmutable} the raw text, and the date when it could be read
     */
    private function dateField(string $text, string $label, array &$reasons): array
    {
        $raw = $this->fieldValues($text, $label)[0] ?? null;

        if ($raw === null) {
            $reasons[] = "The {$label} is missing.";

            return [null, null];
        }

        $date = $this->parseDate($raw);

        if ($date === null) {
            $reasons[] = "The {$label} \"".mb_substr($raw, 0, 40).'" is not a date the portal can read.';
        }

        return [$raw, $date];
    }

    private function parseDate(string $raw): ?CarbonImmutable
    {
        $value = preg_replace('/\s+/', ' ', trim($raw)) ?? '';
        $utc = new DateTimeZone('UTC');

        foreach (self::DATE_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value, $utc);
            $problems = DateTimeImmutable::getLastErrors();

            if ($date !== false && ($problems === false || ($problems['warning_count'] === 0 && $problems['error_count'] === 0))) {
                return CarbonImmutable::instance($date);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $reasons
     */
    private function checkDates(string $title, ?CarbonImmutable $start, ?CarbonImmutable $end, CarbonImmutable $now, array &$reasons): void
    {
        if ($start === null || $end === null) {
            return;
        }

        // An LOA is at least one Cav week (Saturday to the following Friday) and at most 14 days. Coverage
        // excuses every week a leave touches, so a shorter one would excuse a whole week. It need not start
        // on a Saturday. Forum posts end a two-week LOA on the weekday it started (Oct 3 to Oct 17), so the
        // End Date may be the day back: each bound reads it the way that lets the post through, and only a
        // post that is wrong either way is flagged.
        if ($end->lessThan($start)) {
            $reasons[] = 'The End Date is before the Start Date.';
        } elseif ($start->diffInDays($end) < self::SHORTEST_LOA_GAP) {
            $reasons[] = 'The leave covers fewer than 7 days, shorter than one Cav week (Saturday to Friday).';
        } elseif ($start->diffInDays($end) > self::LONGEST_LOA_GAP) {
            $reasons[] = 'The End Date is more than 14 days after the Start Date, longer than an LOA can be.';
        }

        foreach (['Start Date' => $start, 'End Date' => $end] as $label => $date) {
            if (abs($date->diffInDays($now)) > self::SANE_DAYS) {
                $reasons[] = "The {$label} is more than 3 years from today.";
            }
        }

        // The title is generated from the same form, so dates in it that differ mean the post was edited
        // afterwards. Which one is current is not for us to decide.
        if (preg_match('/\|\s*(.+?)\s+-\s+(.+?)\s*$/', $title, $dates) === 1) {
            $titleStart = $this->parseDate($dates[1]);
            $titleEnd = $this->parseDate($dates[2]);

            if ($titleStart !== null && $titleEnd !== null && (! $titleStart->isSameDay($start) || ! $titleEnd->isSameDay($end))) {
                $reasons[] = 'The dates in the title differ from the dates in the post.';
            }
        }
    }

    /**
     * An ELOA post names its own kind in the body as well as the title; the two must agree.
     *
     * @param  list<string>  $reasons
     */
    private function checkBodyType(LoaThreadKind $kind, string $text, array &$reasons): void
    {
        $bodyType = $this->fieldValues($text, 'Type')[0] ?? null;

        if ($bodyType === null) {
            $reasons[] = 'The post has no Type field.';
        } elseif (strtolower($bodyType) !== $kind->bodyType()) {
            $reasons[] = "The title says {$kind->label()} but the post's Type is \"".mb_substr($bodyType, 0, 20).'".';
        }
    }

    /**
     * The id of the PAF thread an ELOA post links to, from its `PAF LINK`. Only the id is used, never the
     * link itself: the portal asks the forum API for that thread, it does not follow arbitrary addresses.
     */
    private function pafThreadId(string $text): ?int
    {
        $link = $this->fieldValues($text, 'PAF LINK')[0] ?? null;

        if ($link !== null && preg_match('~/threads/(?:[^/\s\]]+\.)?(\d+)~', $link, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }

    /**
     * Just the lines the portal read, kept so a row can be re-read later. The free-text reason a
     * trooper typed is left out on purpose: it is personal, and nothing here needs it.
     */
    private function excerpt(?string $username, ?int $forumUserId, ?string $rawStart, ?string $rawEnd, string $text): string
    {
        $lines = [];

        if ($username !== null) {
            $lines[] = 'Username: '.$username.($forumUserId !== null ? " (forum user {$forumUserId})" : '');
        }

        foreach (['Start Date' => $rawStart, 'End Date' => $rawEnd] as $label => $raw) {
            if ($raw !== null) {
                $lines[] = "{$label}: {$raw}";
            }
        }

        $type = $this->fieldValues($text, 'Type')[0] ?? null;

        if ($type !== null) {
            $lines[] = "Type: {$type}";
        }

        return mb_substr(implode("\n", $lines), 0, 500);
    }
}

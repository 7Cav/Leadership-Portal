<?php

namespace App\Commands\StaffReports;

use App\Models\StaffReport;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Support\Time\WeekLabel;
use Illuminate\Support\Collection;

/**
 * One sent staff report as a row of the regiment's reports Google Sheet, in the column order its Google Forms left
 * there. Platoon and company HQ staff go to the staff tab ({@see staff()}), battalion HQ staff, ACD included, to the
 * battalion tab ({@see battalion()}).
 *
 * The staff tab, A to U: Timestamp, name (Lastname.F), Battalion, Modern Phonics Company, Past Phonics Company, Week
 * Ending (Friday), "Did you spend one hour", "What type of Section practice", the {@see STAFF_PRACTICE_COLUMNS} "What
 * Section practice did you attend" columns (all practices go comma-joined into the first), "Name of the FTX", "Select
 * the Date of the FTX", "Select Report to Complete", Platoon Billet, Platoon Assignment, the platoon's Optional notes,
 * Company Billet, the company's Optional notes. The form never asked for other events or presence, so they go in a
 * column after those, V, presence first ("Public server hour", "Office hour (10/5/2026): note", "No public server
 * hour or office hour").
 *
 * The battalion tab, A to P: Timestamp, Lastname.Firstinitial, Organization, Week Ending, then the battalion block
 * (Battalion Billet, "Did you spend one hour", "Section Practice Attended", "What other Event(s) did you attend",
 * Optional notes), then the same block for ACD (ACD Billet first), then Email Address and Score, which stay empty: the
 * portal has neither. An operation, a class or an event (or an FTX sent before) has no column there, so it leads the
 * other events ("Operation: name (10/5/2026)"). "Did you spend one hour" is the presence answer, as on the staff tab.
 */
final class StaffReportSheetRow
{
    /** How many "What Section practice did you attend" columns the staff tab has, one per platoon on the form. */
    public const STAFF_PRACTICE_COLUMNS = 5;

    /** The battalions whose companies the form named in the WWII phonetic alphabet (Past Phonics Company). */
    private const PAST_PHONICS_BATTALIONS = ['2-7'];

    /** WWII phonetic company names, by the modern name's first letter. */
    private const PAST_PHONICS = ['A' => 'Able', 'B' => 'Baker', 'C' => 'Charlie', 'D' => 'Dog', 'E' => 'Easy', 'F' => 'Fox', 'G' => 'George', 'H' => 'How'];

    /**
     * @param  Collection<int, Unit>  $units  the org tree keyed by id
     * @return list<string>
     */
    public static function staff(StaffReport $report, Trooper $trooper, Unit $hq, Collection $units): array
    {
        $company = self::ancestor($hq, UnitType::Company, $units);
        $battalion = self::ancestor($hq, UnitType::Battalion, $units);
        $platoon = $hq->type === UnitType::Platoon;
        $practices = array_pad([self::practices($report)], self::STAFF_PRACTICE_COLUMNS, '');
        $ftx = $report->practice_type === StaffReport::FTX;
        $companyWord = $company === null ? '' : (string) strtok((string) $company->name, ' ');
        $past = $battalion !== null && in_array($battalion->callsign, self::PAST_PHONICS_BATTALIONS, true);

        return [
            self::timestamp($report),
            self::name($trooper),
            self::designation($battalion),
            $past ? '' : $companyWord,
            $past ? (self::PAST_PHONICS[strtoupper(substr($companyWord, 0, 1))] ?? $companyWord) : '',
            self::weekEnding($report),
            self::hour($report),
            self::practiceType($report),
            ...$practices,
            $ftx ? (string) $report->ftx_name : '',
            $ftx && $report->ftx_date !== null ? $report->ftx_date->format('n/j/Y') : '',
            $platoon ? 'Platoon' : 'Company',
            $platoon ? (string) $trooper->billet : '',
            $platoon ? (string) $hq->name : '',
            $platoon ? (string) $report->notes : '',
            $platoon ? '' : (string) $trooper->billet,
            $platoon ? '' : (string) $report->notes,
            self::joined([self::legacyOfficeHour($report), $report->other_events]),
        ];
    }

    /**
     * @return list<string>
     */
    public static function battalion(StaffReport $report, Trooper $trooper, Unit $hq): array
    {
        $named = in_array($report->practice_type, StaffReport::NAMED_TYPES, true)
            ? self::practiceType($report).': '.$report->ftx_name.($report->ftx_date === null ? '' : ' ('.$report->ftx_date->format('n/j/Y').')')
            : null;
        $block = [
            (string) $trooper->billet,
            self::hour($report),
            self::practices($report),
            self::joined([$named, self::legacyOfficeHour($report), $report->other_events]),
            (string) $report->notes,
        ];
        $empty = array_fill(0, 5, '');
        $acd = self::isAcd($hq);

        return [
            self::timestamp($report),
            self::name($trooper),
            (string) $hq->name,
            self::weekEnding($report),
            ...($acd ? $empty : $block),
            ...($acd ? $block : $empty),
            '',
            '',
        ];
    }

    public static function isAcd(Unit $hq): bool
    {
        return $hq->type === UnitType::Battalion && strtoupper((string) $hq->callsign) === 'ACD';
    }

    /** `1/12/2026 18:04:09`, when it was sent, in UTC, as Google Forms stamps a response. */
    private static function timestamp(StaffReport $report): string
    {
        return $report->sent_at->utc()->format('n/j/Y G:i:s');
    }

    /** The Friday the Cav week ends on, as the forum writes a date: `09JAN26`. */
    private static function weekEnding(StaffReport $report): string
    {
        return WeekLabel::ending($report->week_start);
    }

    private static function practiceType(StaffReport $report): string
    {
        return match ($report->practice_type) {
            StaffReport::FTX => 'FTX',
            StaffReport::SP => 'Section Practice (SP)',
            StaffReport::OPERATION => 'Operation',
            StaffReport::CLASS_TYPE => 'Class',
            StaffReport::EVENT => 'Other official event',
            StaffReport::OFFICE_HOUR => 'Office hour',
            default => '',
        };
    }

    /**
     * "Did you spend one hour", which is presence: "Yes, public server", "Yes, office hour (10/6/2026): note" or "No".
     * A report sent before presence was asked keeps what the column showed then, the accountability Yes or No.
     */
    private static function hour(StaffReport $report): string
    {
        return match ($report->presence_type) {
            StaffReport::PRESENCE_PUBLIC_SERVER => 'Yes, public server',
            StaffReport::PRESENCE_OFFICE_HOUR => 'Yes, office hour'.self::officeHourDetail($report),
            StaffReport::PRESENCE_NONE => 'No',
            default => $report->attended ? 'Yes' : 'No',
        };
    }

    /** An office hour sent as accountability, before presence was its own question: it still leads the other events. */
    private static function legacyOfficeHour(StaffReport $report): ?string
    {
        return $report->practice_type === StaffReport::OFFICE_HOUR ? 'Office hour'.self::officeHourDetail($report) : null;
    }

    /** " (10/6/2026): note", the office hour's date and note as the sheet writes them. */
    private static function officeHourDetail(StaffReport $report): string
    {
        return ($report->office_hour_date === null ? '' : ' ('.$report->office_hour_date->format('n/j/Y').')').': '.$report->office_hour_note;
    }

    /** @param  list<?string>  $parts */
    private static function joined(array $parts): string
    {
        return implode('; ', array_filter($parts, fn (?string $part) => $part !== null && $part !== ''));
    }

    private static function practices(StaffReport $report): string
    {
        return implode(', ', array_values(array_filter(array_map(
            fn (array $row) => $row['section'] ?? null,
            $report->practices ?? [],
        ), fn (?string $label) => $label !== null && $label !== '')));
    }

    /** `Vasquez.R`: the forum name, as the forms asked for it. */
    private static function name(Trooper $trooper): string
    {
        return filled($trooper->username) ? (string) $trooper->username : $trooper->display_name;
    }

    private static function designation(?Unit $unit): string
    {
        return $unit === null ? '' : (string) ($unit->callsign ?? $unit->name);
    }

    /**
     * @param  Collection<int, Unit>  $units
     */
    private static function ancestor(Unit $unit, UnitType $type, Collection $units): ?Unit
    {
        for ($node = $unit, $guard = 0; $node !== null && $guard < 8; $guard++) {
            if ($node->type === $type) {
                return $node;
            }
            $node = $node->parent_id === null ? null : $units->get($node->parent_id);
        }

        return null;
    }
}

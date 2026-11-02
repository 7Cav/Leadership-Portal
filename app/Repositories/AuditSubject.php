<?php

namespace App\Repositories;

use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\BattalionSetting;
use App\Models\ChangelogEntry;
use App\Models\ChangelogState;
use App\Models\CounselingRecord;
use App\Models\Course;
use App\Models\CrossSectionSighting;
use App\Models\FireteamAssignment;
use App\Models\FireteamSetting;
use App\Models\Game;
use App\Models\GameServer;
use App\Models\Loa;
use App\Models\PlatoonTrainingTopic;
use App\Models\PortalRoleGrant;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\SectionPracticeSlot;
use App\Models\StaffReport;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\TrainingPlan;
use App\Models\Trooper;
use App\Models\TrooperCourse;
use App\Models\TrooperGameIdentity;
use App\Models\TrooperPlacement;
use App\Models\Unit;
use App\Models\UnitActingLead;
use App\Models\UnitGuidon;
use App\Models\UnitOversight;
use App\Models\UnitTemplateChoice;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Support\Time\WeekLabel;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * What an audit entry is about, in words (#audit-detail): its subject ("Staff report of 1LT Lindqvist.P, week 17OCT26
 * to 23OCT26") and the before and after values of what it changed. Written into `audit_log.details` with each new entry,
 * and worked out again from the stored target for an older entry that has none.
 *
 * Reads only relations already loaded (see {@see RELATIONS}), so it never queries on its own. Only the fields listed in
 * {@see FIELDS} are ever shown as changes: free text such as a counseling reason or a report's notes stays out.
 */
final class AuditSubject
{
    /** What {@see describe()} reads, per target class: loaded once for a whole page of entries, or once per write. */
    public const RELATIONS = [
        SectionPractice::class => ['section'],
        Attendance::class => ['trooper', 'sectionPractice.section'],
        WeeklyReport::class => ['unit'],
        StaffReport::class => ['trooper'],
        SectionPracticeSlot::class => ['section'],
        BattalionSetting::class => ['unit'],
        CounselingRecord::class => ['trooper'],
        UnitGuidon::class => ['unit'],
        Template::class => ['company', 'section'],
        TemplateVersion::class => ['template.company', 'template.section'],
        UnitTemplateChoice::class => ['unit', 'template'],
        PlatoonTrainingTopic::class => ['unit'],
        TrainingPlan::class => ['unit'],
        CrossSectionSighting::class => ['trooper', 'practice.section'],
        TrooperCourse::class => ['trooper', 'course'],
        TrooperGameIdentity::class => ['trooper', 'game'],
        TrooperPlacement::class => ['trooper', 'unit'],
        PortalRoleGrant::class => ['user', 'unit'],
        FireteamAssignment::class => ['section'],
        FireteamSetting::class => ['unit'],
        UnitActingLead::class => ['unit', 'trooper'],
        UnitOversight::class => ['unit', 'trooper'],
        Loa::class => ['trooper'],
    ];

    /** The fields shown as before and after, per target class, with the name each reads as. */
    private const FIELDS = [
        SectionPractice::class => ['slot_moved_to' => 'Moved to', 'slot_cancelled' => 'Cancelled', 'confirmed_at' => 'Confirmed'],
        Attendance::class => ['status' => 'Practice', 'rollcall' => 'Roll call'],
        WeeklyReport::class => ['status' => 'Status'],
        StaffReport::class => ['attended' => 'Attended', 'practice_type' => 'Practice type', 'ftx_date' => 'FTX date', 'office_hour_date' => 'Office hour'],
        BattalionSetting::class => ['presence_minimum_minutes' => 'Presence minimum'],
        CounselingRecord::class => ['type' => 'Type', 'kind' => 'For', 'counseled_on' => 'Dated'],
        ChangelogEntry::class => ['date' => 'Date', 'body' => 'Text', 'major' => 'Major', 'technical' => 'Technical'],
        ChangelogState::class => ['technical_enabled' => 'Technical entries'],
        UnitGuidon::class => ['format' => 'Format', 'primary_color' => 'Primary color', 'secondary_color' => 'Secondary color', 'top_left_text' => 'Top text', 'bottom_right_text' => 'Bottom text', 'large_text' => 'Large text'],
        Template::class => ['name' => 'Name', 'enabled' => 'Switched on'],
        TrainingPlan::class => ['status' => 'Status'],
        PlatoonTrainingTopic::class => ['subject' => 'Topic', 'enforced' => 'Enforced'],
        TrooperCourse::class => ['status' => 'Status'],
        FireteamAssignment::class => ['role' => 'Role'],
        FireteamSetting::class => ['fireteam_order' => 'Order', 'show_fireteams' => 'Show fireteams', 'sl_asl_lead_fireteams' => 'SL and ASL lead fireteams'],
        Unit::class => ['name' => 'Name', 'callsign' => 'Callsign', 'label' => 'Label'],
        Game::class => ['name' => 'Name'],
        GameServer::class => ['name' => 'Name'],
        User::class => ['is_admin' => 'Admin'],
    ];

    private const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    /** How many changed rows of a batch are listed before the rest are counted. */
    private const MEMBER_LIMIT = 20;

    /** @return list<string> */
    public static function relationsOf(string $class): array
    {
        return self::RELATIONS[$class] ?? [];
    }

    /**
     * The details stored with a new entry: the target's subject and what changed on it and, for a batch, on each member
     * row. Called right after the mutation, while each model still knows its values from before the write.
     *
     * @param  list<Model>  $members
     * @return array{subject: ?string, changes: list<array{field: string, from: ?string, to: ?string}>}|null
     */
    public static function details(Model $target, array $members = []): ?array
    {
        $changes = self::changes($target);

        // The members that changed, as copies, so their names load in one query per class rather than one per row.
        $changed = [];
        foreach ($members as $member) {
            if ($member !== $target && ($memberChanges = self::changes($member)) !== []) {
                $changed[] = [clone $member, $memberChanges];
            }
        }
        $listed = array_slice($changed, 0, self::MEMBER_LIMIT);
        collect($listed)->map(fn (array $pair) => $pair[0])->groupBy(fn (Model $model) => $model::class)
            ->each(fn ($models, string $class) => (new EloquentCollection($models->all()))->loadMissing(self::relationsOf($class)));

        foreach ($listed as [$member, $memberChanges]) {
            $who = self::short($member);
            foreach ($memberChanges as $change) {
                $changes[] = [...$change, 'field' => $who !== null ? "{$who}: {$change['field']}" : $change['field']];
            }
        }
        if (count($changed) > self::MEMBER_LIMIT) {
            $changes[] = ['field' => 'And '.(count($changed) - self::MEMBER_LIMIT).' more', 'from' => null, 'to' => null];
        }

        $subject = self::describe(self::loaded($target));

        return $subject === null && $changes === [] ? null : ['subject' => $subject, 'changes' => $changes];
    }

    /** A copy of `$model` with what {@see describe()} reads loaded, leaving the caller's model as it was. */
    private static function loaded(Model $model): Model
    {
        $relations = self::relationsOf($model::class);

        if ($relations === []) {
            return $model;
        }

        $copy = clone $model;

        return $copy->loadMissing($relations);
    }

    /** The entry's subject from its target as stored now, or null when there is nothing more to say than its type. */
    public static function describe(?Model $target): ?string
    {
        $unit = fn (?Unit $unit) => $unit?->callsign ?? $unit?->name ?? 'a unit since deleted';
        $trooper = fn (?Trooper $trooper) => $trooper?->display_name ?? 'a trooper since removed';
        $week = fn (?DateTimeInterface $start) => $start !== null ? ', week '.WeekLabel::range($start) : '';
        $rel = fn (Model $model, string $path) => self::rel($model, $path);

        return match (true) {
            $target instanceof Attendance => 'Practice mark for '.$trooper($rel($target, 'trooper')).', '.$unit($rel($target, 'sectionPractice.section')).$week($rel($target, 'sectionPractice')?->week_start),
            $target instanceof SectionPractice => $unit($rel($target, 'section')).' practice'.$week($target->week_start),
            $target instanceof WeeklyReport => 'Weekly report '.$unit($rel($target, 'unit')).$week($target->week_start),
            $target instanceof StaffReport => 'Staff report of '.$trooper($rel($target, 'trooper')).$week($target->week_start),
            $target instanceof SectionPracticeSlot => 'Practice slot '.$unit($rel($target, 'section')),
            $target instanceof BattalionSetting => 'Presence minimum '.$unit($rel($target, 'unit')),
            $target instanceof CounselingRecord => ($target->type?->short() ?? 'Counseling record').' for '.$trooper($rel($target, 'trooper'))
                .($target->counseled_on !== null ? ' dated '.WeekLabel::date($target->counseled_on) : ''),
            $target instanceof ChangelogEntry => 'Changelog entry '.($target->date !== null ? WeekLabel::date($target->date).' ' : '')
                .'"'.Str::limit((string) $target->body, 60).'"',
            $target instanceof ChangelogState => 'Changelog settings',
            $target instanceof UnitGuidon => 'Guidon of '.$unit($rel($target, 'unit')),
            $target instanceof TemplateVersion => self::template($rel($target, 'template'), $unit).', version '.$target->version,
            $target instanceof Template => self::template($target, $unit),
            $target instanceof UnitTemplateChoice => 'Template pick of '.$unit($rel($target, 'unit'))
                .($rel($target, 'template') !== null ? ': "'.$rel($target, 'template')->name.'"' : ''),
            $target instanceof PlatoonTrainingTopic => 'Training topic '.$unit($rel($target, 'unit')).$week($target->week_start),
            $target instanceof TrainingPlan => 'Training plan '.$unit($rel($target, 'unit')).', '.$target->month,
            $target instanceof CrossSectionSighting => $trooper($rel($target, 'trooper')).' at '.$unit($rel($target, 'practice.section')).$week($target->week_start),
            $target instanceof TrooperCourse => ($rel($target, 'course')?->name ?? 'A course').' for '.$trooper($rel($target, 'trooper')),
            $target instanceof TrooperGameIdentity => ($rel($target, 'game')?->name ?? 'Game').' identity of '.$trooper($rel($target, 'trooper')),
            $target instanceof TrooperPlacement => $trooper($rel($target, 'trooper')).' placed in '.$unit($rel($target, 'unit')),
            $target instanceof PortalRoleGrant => 'Staff grant for '.($rel($target, 'user')?->username ?? 'a user').' on '.$unit($rel($target, 'unit')),
            $target instanceof FireteamAssignment => 'Fireteams '.$unit($rel($target, 'section')),
            $target instanceof FireteamSetting => 'Fireteam display '.$unit($rel($target, 'unit')),
            $target instanceof UnitActingLead => 'Acting lead of '.$unit($rel($target, 'unit')).': '.$trooper($rel($target, 'trooper')),
            $target instanceof UnitOversight => 'Oversight of '.$unit($rel($target, 'unit')).': '.$trooper($rel($target, 'trooper')),
            $target instanceof Loa => 'LOA for '.$trooper($rel($target, 'trooper'))
                .($target->starts_at !== null ? ', '.WeekLabel::date($target->starts_at).' to '.($target->ends_at !== null ? WeekLabel::date($target->ends_at) : 'open') : ''),
            $target instanceof Unit => trim("{$target->callsign} {$target->name}"),
            $target instanceof Trooper => $target->display_name,
            $target instanceof User => $target->username,
            $target instanceof Course => "Course {$target->name}",
            $target instanceof Game => "Game {$target->name}",
            $target instanceof GameServer => "Server {$target->name}",
            default => null,
        };
    }

    /**
     * Before and after of every listed field the write changed. A practice slot reads as one "Slot" change, "none" on
     * the side where there was none (set for the first time, or cleared).
     *
     * @return list<array{field: string, from: ?string, to: ?string}>
     */
    public static function changes(Model $model): array
    {
        if ($model instanceof SectionPracticeSlot) {
            $slot = fn (?array $values) => $values === null ? 'none' : (self::WEEKDAYS[(int) $values['weekday']] ?? '?').' '.self::zulu((int) $values['start_minute']);
            $now = ['weekday' => $model->weekday, 'start_minute' => $model->start_minute];
            $before = [...$now, ...array_intersect_key($model->getPrevious(), $now)];

            return match (true) {
                ! $model->exists => [['field' => 'Slot', 'from' => $slot($now), 'to' => 'none']],
                $model->wasRecentlyCreated => [['field' => 'Slot', 'from' => 'none', 'to' => $slot($now)]],
                $before == $now => [],
                default => [['field' => 'Slot', 'from' => $slot($before), 'to' => $slot($now)]],
            };
        }

        $fields = self::FIELDS[$model::class] ?? [];
        if ($fields === [] || ! $model->exists) {
            return [];
        }

        $previous = $model->getPrevious();
        $changed = array_intersect_key($model->getChanges(), $fields);

        // A first mark creates its row: it reads "none" before, the same as a later change from no mark.
        if ($model instanceof Attendance && $model->wasRecentlyCreated && $changed === []) {
            $changed = array_filter(array_intersect_key($model->getAttributes(), $fields), fn ($value) => $value !== null);
        }
        if ($changed === []) {
            return [];
        }

        // The old values read through the model's own casts, from a copy holding them.
        $before = (clone $model)->setRawAttributes([...$model->getAttributes(), ...$previous]);

        $changes = [];
        foreach (array_keys($changed) as $field) {
            $from = self::format(array_key_exists($field, $previous) ? $before->{$field} : null);
            $to = self::format($model->{$field});
            if ($from !== $to) {
                $changes[] = ['field' => $fields[$field], 'from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    /** A member row's short name in a batch's change list: the trooper it is about, when it is about one. */
    private static function short(Model $model): ?string
    {
        $trooper = self::rel($model, 'trooper');

        return $trooper instanceof Trooper ? $trooper->display_name : self::describe($model);
    }

    private static function format(mixed $value): ?string
    {
        return match (true) {
            $value === null => 'none',
            $value instanceof AttendanceStatus => match ($value) {
                AttendanceStatus::Present => 'Attended',
                AttendanceStatus::Missed => 'Not attended',
                AttendanceStatus::Makeup => 'Make-up complete',
                AttendanceStatus::Excused => 'Excused',
                AttendanceStatus::Loa => 'LOA',
            },
            $value instanceof RollcallSignature => Str::ucfirst(str_replace('_', ' ', $value->value)),
            $value instanceof BackedEnum && method_exists($value, 'label') => $value->label(),
            $value instanceof BackedEnum => Str::ucfirst(str_replace('_', ' ', (string) $value->value)),
            $value instanceof DateTimeInterface => self::moment($value),
            is_bool($value) => $value ? 'Yes' : 'No',
            is_scalar($value) => Str::limit((string) $value, 60),
            default => null,
        };
    }

    /** `28OCT26`, or `Wed 28OCT26 2359Z` for a moment with a time of day. */
    private static function moment(DateTimeInterface $value): string
    {
        $utc = CarbonImmutable::instance($value)->utc();

        return $utc->format('His') === '000000'
            ? WeekLabel::date($utc)
            : $utc->format('D').' '.WeekLabel::date($utc).' '.$utc->format('Hi').'Z';
    }

    private static function zulu(int $minute): string
    {
        return sprintf('%02d%02dZ', intdiv($minute, 60), $minute % 60);
    }

    /** @param  \Closure(?Unit): string  $unit */
    private static function template(?Template $template, \Closure $unit): string
    {
        return match (true) {
            $template === null => 'A template since deleted',
            $template->isRegimentDefault() => 'Regiment default template',
            default => 'Template "'.$template->name.'" of '.$unit(self::rel($template, 'section') ?? self::rel($template, 'company')),
        };
    }

    /** A loaded relation along a dotted path, or null where one is missing or not loaded (never a lazy load). */
    private static function rel(Model $model, string $path): mixed
    {
        $current = $model;
        foreach (explode('.', $path) as $name) {
            if (! $current instanceof Model || ! $current->relationLoaded($name)) {
                return null;
            }
            $current = $current->getRelation($name);
        }

        return $current;
    }
}

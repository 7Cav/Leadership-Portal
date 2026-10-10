<?php

use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\Loa;
use App\Models\PortalRoleGrant;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;

// Story 3.8: saving a week's marks from the rollcall screen goes through the audited MarkAttendance Command, scope-gated.
// "Now" is Thursday 2026-10-08, so the current Cav week opens Saturday 2026-10-03.
// Fixture tree: 7CAV ─ 1-7 ─ A/1-7 ─ 1/A ─┬─ 1/1/A (section, led by the viewer)
//                                          └─ 2/1/A

const MARK_WEEK = '2026-10-03';

function markTrooper(Unit $section, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

function markSave($test, Unit $section, array $marks, string $week = MARK_WEEK)
{
    return $test->post(route('rollcall.store', $section->id), ['week' => $week, 'marks' => $marks]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));
    $units = app(UnitRepository::class);

    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
    $this->sibling = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $platoon->id);

    $leaderTrooper = markTrooper($this->section, ['cav_position_id' => 501]);
    UnitCommandMapping::factory()->create(['unit_id' => $this->section->id, 'cav_position_id' => 501]);
    $this->leader = User::factory()->create(['cav_id' => $leaderTrooper->cav_id]);
    $this->troopers = Trooper::factory()->count(3)->create()->each(fn (Trooper $t) => $t->forceFill(['primary_section_id' => $this->section->id])->save());
});

describe('saving marks', function () {
    it('writes each mark as a manual mark and the section\'s practice for the week', function () {
        markSave($this->actingAs($this->leader), $this->section, [
            ['trooper_id' => $this->troopers[0]->id, 'status' => 'present', 'rollcall' => 'will_attend'],
            ['trooper_id' => $this->troopers[1]->id, 'status' => 'missed'],
            ['trooper_id' => $this->troopers[2]->id, 'status' => 'makeup'],
        ])->assertRedirect(route('rollcall.show', ['unit' => $this->section->id, 'week' => MARK_WEEK]));

        $rows = Attendance::query()->get()->keyBy('trooper_id');

        expect(SectionPractice::query()->sole()->week_start->toDateString())->toBe(MARK_WEEK)
            ->and($rows[$this->troopers[0]->id]->status)->toBe(AttendanceStatus::Present)
            ->and($rows[$this->troopers[0]->id]->rollcall)->toBe(RollcallSignature::WillAttend)
            ->and($rows[$this->troopers[1]->id]->status)->toBe(AttendanceStatus::Missed)
            ->and($rows[$this->troopers[2]->id]->status)->toBe(AttendanceStatus::Makeup)
            ->and($rows->every(fn (Attendance $row) => $row->source === AttendanceSource::Manual || $row->source === null))->toBeTrue();
    });

    it('records attached as presence credited to the home section, distinct from present', function () {
        markSave($this->actingAs($this->leader), $this->section, [['trooper_id' => $this->troopers[0]->id, 'status' => 'attached']])->assertRedirect();

        expect(Attendance::query()->sole()->status)->toBe(AttendanceStatus::Attached);
    });

    it('takes any day in the week as that week', function () {
        markSave($this->actingAs($this->leader), $this->section, [['trooper_id' => $this->troopers[0]->id, 'status' => 'present']], '2026-10-07')->assertRedirect();

        expect(SectionPractice::query()->sole()->week_start->toDateString())->toBe(MARK_WEEK);
    });

    it('updates a week submitted again rather than duplicating any row, and audits each submit', function () {
        markSave($this->actingAs($this->leader), $this->section, [['trooper_id' => $this->troopers[0]->id, 'status' => 'missed']]);
        markSave($this, $this->section, [['trooper_id' => $this->troopers[0]->id, 'status' => 'present']]);

        expect(Attendance::query()->count())->toBe(1)
            ->and(Attendance::query()->sole()->status)->toBe(AttendanceStatus::Present)
            ->and(SectionPractice::query()->count())->toBe(1)
            ->and(AuditLogEntry::query()->where('action', 'attendance.marked')->count())->toBe(2);
    });

    it('writes a single trooper\'s rollcall as exactly one attendance row plus its practice row', function () {
        markSave($this->actingAs($this->leader), $this->section, [['trooper_id' => $this->troopers[0]->id, 'status' => 'present']]);

        expect(Attendance::query()->count())->toBe(1)->and(SectionPractice::query()->count())->toBe(1);
    });

    it('lets a leader override a mark an auto source made, and the override is manual', function () {
        $practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => MARK_WEEK]);
        Attendance::factory()->create(['trooper_id' => $this->troopers[0]->id, 'section_practice_id' => $practice->id, 'status' => AttendanceStatus::Present, 'source' => AttendanceSource::Auto]);

        markSave($this->actingAs($this->leader), $this->section, [['trooper_id' => $this->troopers[0]->id, 'status' => 'missed']]);

        $row = Attendance::query()->sole();

        expect($row->status)->toBe(AttendanceStatus::Missed)->and($row->source)->toBe(AttendanceSource::Manual);
    });

    it('records only the rollcall signature when no status is sent, leaving the practice unmarked', function () {
        markSave($this->actingAs($this->leader), $this->section, [['trooper_id' => $this->troopers[0]->id, 'rollcall' => 'cannot_attend']]);

        $row = Attendance::query()->sole();

        expect($row->rollcall)->toBe(RollcallSignature::CannotAttend)->and($row->status)->toBeNull()->and($row->source)->toBeNull();
    });

    it('lets a signature be set back to unsigned', function () {
        markSave($this->actingAs($this->leader), $this->section, [['trooper_id' => $this->troopers[0]->id, 'rollcall' => 'will_attend']]);
        markSave($this, $this->section, [['trooper_id' => $this->troopers[0]->id, 'rollcall' => 'unsigned']]);

        expect(Attendance::query()->sole()->rollcall)->toBe(RollcallSignature::Unsigned);
    });

    it('accepts an empty submission, which only records that the section\'s practice exists', function () {
        markSave($this->actingAs($this->leader), $this->section, [])->assertRedirect();

        expect(SectionPractice::query()->count())->toBe(1)->and(Attendance::query()->count())->toBe(0);
    });

    it('can correct a past week', function () {
        markSave($this->actingAs($this->leader), $this->section, [['trooper_id' => $this->troopers[0]->id, 'status' => 'missed']], '2026-09-19')->assertRedirect();

        expect(SectionPractice::query()->sole()->week_start->toDateString())->toBe('2026-09-19');
    });
});

describe('what is refused', function () {
    it('refuses loa as a status, since it is derived and never entered', function () {
        markSave($this->actingAs($this->leader), $this->section, [['trooper_id' => $this->troopers[0]->id, 'status' => 'loa']])->assertSessionHasErrors('marks.0.status');

        expect(Attendance::query()->count())->toBe(0);
    });

    it('refuses a counting status for a trooper whose leave covers the week, writing nothing for anyone', function () {
        Loa::factory()->create(['trooper_id' => $this->troopers[1]->id, 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-30']);

        markSave($this->actingAs($this->leader), $this->section, [
            ['trooper_id' => $this->troopers[0]->id, 'status' => 'present'],
            ['trooper_id' => $this->troopers[1]->id, 'status' => 'present'],
        ])->assertSessionHasErrors('marks');

        expect(Attendance::query()->count())->toBe(0)->and(SectionPractice::query()->count())->toBe(0);
    });

    it('still takes a trooper on leave\'s rollcall signature', function () {
        Loa::factory()->create(['trooper_id' => $this->troopers[1]->id, 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-30']);

        markSave($this->actingAs($this->leader), $this->section, [['trooper_id' => $this->troopers[1]->id, 'rollcall' => 'cannot_attend']])->assertRedirect();

        expect(Attendance::query()->sole()->rollcall)->toBe(RollcallSignature::CannotAttend);
    });

    it('refuses a trooper placed in another section', function () {
        $stranger = markTrooper($this->sibling);

        markSave($this->actingAs($this->leader), $this->section, [['trooper_id' => $stranger->id, 'status' => 'present']])->assertSessionHasErrors('marks');

        expect(Attendance::query()->count())->toBe(0);
    });

    it('refuses an entry with nothing to record, and a trooper sent twice', function () {
        markSave($this->actingAs($this->leader), $this->section, [['trooper_id' => $this->troopers[0]->id]])->assertSessionHasErrors('marks');
        markSave($this, $this->section, [
            ['trooper_id' => $this->troopers[0]->id, 'status' => 'present'],
            ['trooper_id' => $this->troopers[0]->id, 'status' => 'missed'],
        ])->assertSessionHasErrors('marks');

        expect(Attendance::query()->count())->toBe(0);
    });

    it('refuses a status or signature it does not know, a missing week and a missing marks list', function () {
        markSave($this->actingAs($this->leader), $this->section, [['trooper_id' => $this->troopers[0]->id, 'status' => 'asleep']])->assertSessionHasErrors('marks.0.status');
        markSave($this, $this->section, [['trooper_id' => $this->troopers[0]->id, 'rollcall' => 'maybe']])->assertSessionHasErrors('marks.0.rollcall');
        $this->post(route('rollcall.store', $this->section->id), ['marks' => []])->assertSessionHasErrors('week');
        $this->post(route('rollcall.store', $this->section->id), ['week' => MARK_WEEK])->assertSessionHasErrors('marks');
        markSave($this, $this->section, [], 'banana')->assertSessionHasErrors('week');
    });

    it('refuses a week that has not begun', function () {
        markSave($this->actingAs($this->leader), $this->section, [['trooper_id' => $this->troopers[0]->id, 'status' => 'present']], '2026-10-10')->assertSessionHasErrors('week');

        expect(SectionPractice::query()->count())->toBe(0);
    });

    it('refuses a section outside the leader\'s scope, whatever the page showed, and writes nothing', function () {
        $stranger = markTrooper($this->sibling);

        markSave($this->actingAs($this->leader), $this->sibling, [['trooper_id' => $stranger->id, 'status' => 'present']])->assertForbidden();

        expect(Attendance::query()->count())->toBe(0)->and(SectionPractice::query()->count())->toBe(0);
    });

    it('refuses someone with no scope at all', function () {
        markSave($this->actingAs(User::factory()->create()), $this->section, [])->assertForbidden();
    });

    it('sends a signed-out visitor to sign in', function () {
        markSave($this, $this->section, [])->assertRedirect(route('login'));
    });

    it('answers a unit that is not a section with 404', function () {
        $platoon = Unit::query()->where('type', UnitType::Platoon->value)->first();

        markSave($this->actingAs($this->leader), $platoon, [])->assertNotFound();
    });

    it('is refused while viewing as someone else, since a preview is read-only (1.13)', function () {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section->id])->assertSessionHasNoErrors();

        markSave($this, $this->section, [['trooper_id' => $this->troopers[0]->id, 'status' => 'present']])->assertForbidden();

        expect(Attendance::query()->count())->toBe(0);
    });

    it('lets a leader with scope over a platoon mark any section below it', function () {
        $staff = User::factory()->create();
        PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $this->section->parent_id]);
        $stranger = markTrooper($this->sibling);

        markSave($this->actingAs($staff), $this->sibling, [['trooper_id' => $stranger->id, 'status' => 'present']])->assertRedirect();

        expect(Attendance::query()->sole()->trooper_id)->toBe($stranger->id);
    });
});

describe('the saved week reads back', function () {
    it('shows the saved marks as saved, with their source, on the next load', function () {
        markSave($this->actingAs($this->leader), $this->section, [['trooper_id' => $this->troopers[0]->id, 'status' => 'makeup', 'rollcall' => 'will_attend']]);

        $row = collect($this->get(route('rollcall.show', $this->section->id))->viewData('page')['props']['troopers'])->firstWhere('id', $this->troopers[0]->id);

        expect($row['practice'])->toBe(['value' => 'makeup', 'origin' => 'saved', 'source' => 'manual'])
            ->and($row['rollcall'])->toBe('will_attend');
    });

    it('turns a carried present into a saved manual one once it is submitted', function () {
        $prior = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => '2026-09-26']);
        Attendance::factory()->create(['trooper_id' => $this->troopers[0]->id, 'section_practice_id' => $prior->id, 'status' => AttendanceStatus::Present, 'source' => AttendanceSource::Manual, 'rollcall' => RollcallSignature::Unsigned]);
        $row = fn () => collect($this->get(route('rollcall.show', $this->section->id))->viewData('page')['props']['troopers'])->firstWhere('id', $this->troopers[0]->id)['practice'];

        $this->actingAs($this->leader);

        expect($row()['origin'])->toBe('carried');

        markSave($this, $this->section, [['trooper_id' => $this->troopers[0]->id, 'status' => 'present']]);

        expect($row())->toBe(['value' => 'present', 'origin' => 'saved', 'source' => 'manual']);
    });
});

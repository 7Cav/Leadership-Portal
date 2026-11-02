<?php

use App\Models\AuditLogEntry;
use App\Models\PageVisit;
use App\Models\SectionPracticeSlot;
use App\Models\StaffReport;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// The docs pages tell the tutorial what the portal already knows the reader did (`portalDone`), so
// those steps tick themselves. Read for the viewer, or for the person a view-as preview shows.

beforeEach(function () {
    config(['portal.docs' => true]);

    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->section = $repo->create(UnitType::Section, '1st Section', '1/1/A/1-7', $regiment->id);
    UnitCommandMapping::factory()->create(['unit_id' => $this->section->id, 'cav_position_id' => 5900]);
    $this->trooper = Trooper::factory()->create(['cav_id' => 901, 'cav_position_id' => 5900, 'status' => TrooperStatus::Combat]);
    $this->leader = User::factory()->create(['cav_id' => 901]);
});

function portalDone(object $test, User $viewer): array
{
    $done = null;
    $test->actingAs($viewer)->get('/docs')->assertOk()->assertInertia(function (Assert $page) use (&$done) {
        $done = $page->toArray()['props']['portalDone'];
    });

    return $done;
}

function audited(User $actor, string $action, ?User $onBehalfOf = null): void
{
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'action' => $action])
        ->forceFill(['on_behalf_of_id' => $onBehalfOf?->id])->save();
}

it('ticks only signing in for a leader who has done nothing yet', function () {
    expect(portalDone($this, $this->leader))->toBe(['sign-in-and-find-your-unit']);
});

it('ticks reading the roster once the reader opened it', function () {
    PageVisit::factory()->create(['user_id' => $this->leader->id, 'url' => '/rosterx']);
    expect(portalDone($this, $this->leader))->not->toContain('read-your-roster');

    PageVisit::factory()->create(['user_id' => $this->leader->id, 'url' => '/roster?unit=3']);
    expect(portalDone($this, $this->leader))->toContain('read-your-roster');
});

it('ticks roll call for a mark, a confirmed practice or a posted roll call by the reader', function (string $action) {
    audited($this->leader, $action);

    expect(portalDone($this, $this->leader))->toContain('run-roll-call');
})->with(['attendance.marked', 'practice.confirmed', 'rollcall.posted']);

it('ticks the practice slot when the reader set one or their section has one', function () {
    audited($this->leader, 'practice_slot.set');
    expect(portalDone($this, $this->leader))->toContain('set-your-practice-slot');

    DB::table('audit_log')->delete();
    expect(portalDone($this, $this->leader))->not->toContain('set-your-practice-slot');

    SectionPracticeSlot::query()->create(['section_id' => $this->section->id, 'weekday' => 3, 'start_minute' => 1200]);
    expect(portalDone($this, $this->leader))->toContain('set-your-practice-slot');
});

it('ticks game identities once the reader linked at least one', function () {
    audited($this->leader, 'game_identity.linked');

    expect(portalDone($this, $this->leader))->toContain('link-game-identities');
});

it('ticks the weekly report once the reader sent one', function () {
    audited($this->leader, 'weekly_report.sent');

    expect(portalDone($this, $this->leader))->toContain('send-the-weekly-report');
});

it('ticks the staff report when one was sent for the reader', function () {
    StaffReport::query()->create(['trooper_id' => $this->trooper->id, 'week_start' => now()->startOfWeek(), 'attended' => true, 'practices' => [], 'sent_at' => now()]);

    expect(portalDone($this, $this->leader))->toContain('send-a-staff-report');
});

it('never ticks where to get help, and ignores what someone else did', function () {
    $other = User::factory()->create();
    foreach (['attendance.marked', 'practice_slot.set', 'game_identity.linked', 'weekly_report.sent', 'staff_report.sent'] as $action) {
        audited($other, $action);
    }

    expect(portalDone($this, $this->leader))->toBe(['sign-in-and-find-your-unit']);
});

it('credits an act-as write to the person acted as, not the Admin', function () {
    $admin = User::factory()->admin()->create();
    audited($admin, 'weekly_report.sent', $this->leader);

    expect(portalDone($this, $this->leader))->toContain('send-the-weekly-report')
        ->and(portalDone($this, $admin))->not->toContain('send-the-weekly-report');
});

it('answers for the previewed person during a view as', function () {
    $admin = User::factory()->admin()->create();
    audited($this->leader, 'weekly_report.sent');
    audited($admin, 'game_identity.linked');

    $this->actingAs($admin)->post('/view-as', ['kind' => 'user', 'id' => $this->leader->id]);

    expect(portalDone($this, $admin))->toContain('send-the-weekly-report')->not->toContain('link-game-identities');
});

it('reads the progress in a few queries, not one per step', function () {
    audited($this->leader, 'weekly_report.sent');
    portalDone($this, $this->leader);

    DB::enableQueryLog();
    portalDone($this, $this->leader);
    $progress = collect(DB::getQueryLog())->filter(fn (array $q) => preg_match('/^select.*(audit_log|page_visits|section_practice_slots|staff_reports)/i', $q['query']) === 1);

    expect($progress->count())->toBeLessThanOrEqual(4);
});

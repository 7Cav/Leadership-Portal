<?php

use App\Commands\StaffReports\SendStaffReport;
use App\Commands\StaffReports\SendStaffReportHandler;
use App\Commands\StaffReports\StaffReportRefusedException;
use App\Commands\Units\AccountabilityOptionsForbiddenException;
use App\Commands\Units\AccountabilityRules;
use App\Commands\Units\InvalidAccountabilityOptionsException;
use App\Commands\Units\SetAccountabilityOptions;
use App\Models\AuditLogEntry;
use App\Models\StaffReport;
use App\Models\Trooper;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../StaffPractices/fixture.php';

// What counts for accountability, per unit: an Admin or Regimental HQ staff set a battalion's options, its HQ staff
// those of the units under it inside that set, a unit with none follows the unit above it, and the staff report and
// a section's makeups read the set in effect. With nothing set, nothing changes.

beforeEach(function () {
    $this->tree = staffPracticeTree();
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    $u = $this->tree['units'];
    $units = app(UnitRepository::class);
    // A second company in the battalion, for "elsewhere in the battalion".
    $bravo = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', $u['battalion']->id);
    $bravoPlatoon = $units->create(UnitType::Platoon, '1st Platoon', '1/B/1-7', $bravo->id);
    $this->elsewhere = $units->create(UnitType::Section, '1st Section', '1/1/B/1-7', $bravoPlatoon->id);
    Trooper::factory()->create(['primary_section_id' => $this->elsewhere->id]);
    Trooper::factory()->create(['primary_section_id' => $u['host']->id]);

    $this->admin = $this->tree['users']['admin'];
    $bnStaff = Trooper::factory()->create(['primary_section_id' => $u['battalion']->id, 'username' => 'Stone.A']);
    $this->bnStaffUser = User::factory()->create(['cav_id' => $bnStaff->cav_id, 'username' => 'Stone.A']);
    $this->rules = app(AccountabilityRules::class);
    $this->set = fn (User $actor, $unit, ?array $options) => app(SetAccountabilityOptions::class)->handle($actor, $unit->id, $options);
});

function accountabilitySend(Trooper $trooper, User $actor, bool $attended, array $sectionIds, ?string $type = null, ?string $name = null, ?string $date = null): StaffReport
{
    return app(SendStaffReportHandler::class)->handle($actor, new SendStaffReport(
        $trooper->id, staffPracticeWeek(), $attended, $sectionIds, $type, $name, $date, null, null, null, null, null, presenceType: 'none',
    ));
}

it('uses today\'s rules when nothing is set', function () {
    $u = $this->tree['units'];

    expect($this->rules->effective($u['battalion'])['options'])->toBe(AccountabilityRules::defaultFor(UnitType::Battalion))
        ->and($this->rules->effective($u['company'])['options'])->toBe([AccountabilityRules::SP_OWN_UNIT, AccountabilityRules::FTX])
        ->and($this->rules->effective($u['platoon'])['source'])->toBe('default')
        ->and($this->rules->effective($u['host'])['options'])->toBe(AccountabilityRules::defaultFor(UnitType::Section))
        ->and($this->rules->makeupCounts($u['host']->id, $this->elsewhere->id))->toBeTrue()
        ->and($this->rules->staff($u['platoon'])['types'])->toBe(['sp', 'ftx'])
        ->and($this->rules->staff($u['battalion'])['types'])->toBe(['sp', 'operation', 'class', 'event']);
});

it('lets an Admin set a battalion and its HQ staff the units under it, never wider, audited once per change', function () {
    $u = $this->tree['units'];

    ($this->set)($this->admin, $u['battalion'], [AccountabilityRules::SP_OWN_UNIT, AccountabilityRules::SP_BATTALION, AccountabilityRules::FTX]);
    ($this->set)($this->bnStaffUser, $u['company'], [AccountabilityRules::SP_OWN_UNIT]);
    // The same again changes nothing and is not logged.
    ($this->set)($this->bnStaffUser, $u['company'], [AccountabilityRules::SP_OWN_UNIT]);

    expect($this->rules->effective($u['company'])['source'])->toBe('own')
        ->and(AuditLogEntry::query()->where('action', SetAccountabilityOptions::ACTION)->count())->toBe(2);

    // The platoon follows the company; the operation is cut by the battalion's set.
    $platoon = $this->rules->effective($u['platoon']);
    expect($platoon['source'])->toBe('inherited')->and($platoon['from']->id)->toBe($u['company']->id)
        ->and($platoon['options'])->toBe([AccountabilityRules::SP_OWN_UNIT])
        ->and($platoon['allowed'])->toBe([AccountabilityRules::SP_OWN_UNIT, AccountabilityRules::SP_BATTALION, AccountabilityRules::FTX]);

    expect(fn () => ($this->set)($this->bnStaffUser, $u['company'], [AccountabilityRules::OPERATION]))->toThrow(InvalidAccountabilityOptionsException::class)
        ->and(fn () => ($this->set)($this->bnStaffUser, $u['company'], []))->toThrow(InvalidAccountabilityOptionsException::class)
        ->and(fn () => ($this->set)($this->bnStaffUser, $u['company'], ['nope']))->toThrow(InvalidAccountabilityOptionsException::class)
        ->and(fn () => ($this->set)($this->bnStaffUser, $u['battalion'], [AccountabilityRules::FTX]))->toThrow(AccountabilityOptionsForbiddenException::class)
        ->and(fn () => ($this->set)($this->tree['users']['co'], $u['platoon'], [AccountabilityRules::FTX]))->toThrow(AccountabilityOptionsForbiddenException::class)
        ->and(fn () => ($this->set)($this->admin, $u['regiment'], [AccountabilityRules::FTX]))->toThrow(AccountabilityOptionsForbiddenException::class);

    // Null clears the company's own: back to the default, cut to the battalion's set.
    ($this->set)($this->bnStaffUser, $u['company'], null);
    expect($this->rules->effective($u['company']))->toMatchArray(['source' => 'default', 'options' => [AccountabilityRules::SP_OWN_UNIT, AccountabilityRules::FTX]]);
});

it('offers and accepts only what the HQ\'s options count on the staff report', function () {
    $u = $this->tree['units'];
    $psg = $this->tree['troopers']['psg'];
    $psgUser = $this->tree['users']['psg'];

    // The platoon counts a practice elsewhere in the battalion only: no FTX, nothing in its own platoon.
    ($this->set)($this->bnStaffUser, $u['platoon'], [AccountabilityRules::SP_BATTALION]);

    expect($this->rules->staff($u['platoon']))->toMatchArray(['types' => ['sp'], 'own' => false, 'battalion' => true]);

    $refusal = function (Closure $write) {
        try {
            $write();
        } catch (StaffReportRefusedException $e) {
            return $e->reason;
        }

        return null;
    };

    expect($refusal(fn () => accountabilitySend($psg, $psgUser, true, [], 'ftx', 'Op Dawn', '2026-10-05')))->toBe('practice_type')
        ->and($refusal(fn () => accountabilitySend($psg, $psgUser, true, [$u['host']->id])))->toBe('not_in_unit');

    $report = accountabilitySend($psg, $psgUser, true, [$this->elsewhere->id]);
    expect($report->practice_type)->toBe('sp');

    $this->actingAs($psgUser)->get(route('staff-report.show', ['trooper' => $psg->id, 'week' => '2026-10-03']))->assertOk();
});

it('sets the options over HTTP on the roster and shows them there', function () {
    $u = $this->tree['units'];

    $this->actingAs($this->admin)->postJson("/roster/{$u['battalion']->id}/accountability", ['options' => [AccountabilityRules::SP_OWN_UNIT, AccountabilityRules::FTX]])
        ->assertOk()
        ->assertJsonPath('accountability.source', 'own')
        ->assertJsonPath('accountability.options', [AccountabilityRules::SP_OWN_UNIT, AccountabilityRules::FTX])
        ->assertJsonPath('accountability.canEdit', true);

    $this->actingAs($this->bnStaffUser)->postJson("/roster/{$u['host']->id}/accountability", ['options' => [AccountabilityRules::SP_OWN_UNIT]])
        ->assertOk()
        ->assertJsonPath('accountability.kind', 'makeup')
        ->assertJsonPath('accountability.choices.1.key', AccountabilityRules::FTX);

    $this->actingAs($this->bnStaffUser)->postJson("/roster/{$u['host']->id}/accountability", ['options' => [AccountabilityRules::OPERATION]])->assertStatus(422);
    $this->actingAs($this->tree['users']['sl'])->postJson("/roster/{$u['host']->id}/accountability", ['options' => null])->assertForbidden();

    // Only the section's own company counts now: a practice in Bravo Company gives no makeup.
    expect($this->rules->makeupCounts($u['host']->id, $u['other']->id))->toBeTrue()
        ->and($this->rules->makeupCounts($u['host']->id, $this->elsewhere->id))->toBeFalse();
});

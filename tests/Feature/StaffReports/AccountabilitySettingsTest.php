<?php

use App\Commands\StaffReports\SendStaffReport;
use App\Commands\StaffReports\SendStaffReportHandler;
use App\Commands\StaffReports\StaffReportRefusedException;
use App\Commands\Units\AccountabilityOptionsForbiddenException;
use App\Commands\Units\AccountabilityRules;
use App\Commands\Units\InvalidAccountabilityOptionsException;
use App\Commands\Units\SetAccountabilityOptions;
use App\Models\AdminBillet;
use App\Models\AdminBilletStatus;
use App\Models\AuditLogEntry;
use App\Models\StaffReport;
use App\Models\Trooper;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../StaffPractices/fixture.php';

// What counts for accountability, per unit: an Admin or Regimental HQ staff set a battalion's options, its HQ staff
// (and they) those of each unit under it inside that set, a unit with none takes its echelon's default cut to the
// battalion's set, and the staff report and a section's makeups read the set in effect. With nothing set, nothing changes.

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

    // The platoon is set on its own: with nothing set it takes the platoon default, cut to the battalion's set.
    $platoon = $this->rules->effective($u['platoon']);
    expect($platoon['source'])->toBe('default')
        ->and($platoon['options'])->toBe([AccountabilityRules::SP_OWN_UNIT, AccountabilityRules::FTX])
        ->and($platoon['allowed'])->toBe([AccountabilityRules::SP_OWN_UNIT, AccountabilityRules::SP_BATTALION, AccountabilityRules::FTX]);

    expect(fn () => ($this->set)($this->bnStaffUser, $u['company'], [AccountabilityRules::OPERATION]))->toThrow(InvalidAccountabilityOptionsException::class)
        ->and(fn () => ($this->set)($this->bnStaffUser, $u['company'], []))->toThrow(InvalidAccountabilityOptionsException::class)
        ->and(fn () => ($this->set)($this->bnStaffUser, $u['company'], ['nope']))->toThrow(InvalidAccountabilityOptionsException::class)
        // Coming later: never stored.
        ->and(fn () => ($this->set)($this->admin, $u['battalion'], [AccountabilityRules::SERVER_HOUR]))->toThrow(InvalidAccountabilityOptionsException::class)
        ->and(fn () => ($this->set)($this->bnStaffUser, $u['host'], [AccountabilityRules::FTX]))->toThrow(InvalidAccountabilityOptionsException::class)
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

    // The battalion's set is read only on its roster; Admins and Regimental HQ staff change it on the org structure page.
    $this->actingAs($this->admin)->get(route('settings.org-structure'))->assertOk()
        ->assertInertia(fn ($page) => $page->where("battalionAccountability.{$u['battalion']->id}.canEdit", true));
    $this->actingAs($this->admin)->get(route('roster.index', ['unit' => $u['battalion']->id]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('accountability', null));

    $this->actingAs($this->admin)->postJson("/roster/{$u['battalion']->id}/accountability", ['options' => [AccountabilityRules::SP_OWN_UNIT, AccountabilityRules::FTX]])
        ->assertOk()
        ->assertJsonPath('accountability.source', 'own')
        ->assertJsonPath('accountability.options', [AccountabilityRules::SP_OWN_UNIT, AccountabilityRules::FTX])
        ->assertJsonPath('accountability.canEdit', false);

    $this->actingAs($this->bnStaffUser)->postJson("/roster/{$u['host']->id}/accountability", ['options' => [AccountabilityRules::SP_OWN_UNIT]])
        ->assertOk()
        ->assertJsonPath('accountability.kind', 'makeup')
        ->assertJsonPath('accountability.choices.1.key', AccountabilityRules::FTX)
        ->assertJsonPath('accountability.choices.1.pending', true);

    $this->actingAs($this->bnStaffUser)->postJson("/roster/{$u['host']->id}/accountability", ['options' => [AccountabilityRules::OPERATION]])->assertStatus(422);
    $this->actingAs($this->tree['users']['sl'])->postJson("/roster/{$u['host']->id}/accountability", ['options' => null])->assertForbidden();

    // Only the section's own company counts now: a practice in Bravo Company gives no makeup.
    expect($this->rules->makeupCounts($u['host']->id, $u['other']->id))->toBeTrue()
        ->and($this->rules->makeupCounts($u['host']->id, $this->elsewhere->id))->toBeFalse();
});

it('shows the roster Admin tab and sends its data only to those who may change something on it', function () {
    $u = $this->tree['units'];
    $units = app(UnitRepository::class);
    // Regimental HQ staff: placed on the regiment in a Regimental HQ billet.
    $regimentStaff = Trooper::factory()->create(['primary_section_id' => $u['regiment']->id, 'cav_position_id' => 9010]);
    AdminBillet::query()->create(['cav_position_id' => 9010, 'proposed_title' => 'Regimental Chief of Staff', 'proposed_group_title' => 'Regimental HQ', 'status' => AdminBilletStatus::Pending]);
    $regimentStaffUser = User::factory()->create(['cav_id' => $regimentStaff->cav_id]);
    $otherBattalion = $units->create(UnitType::Battalion, '2nd Battalion', '2-7', $u['regiment']->id);
    $otherStaff = Trooper::factory()->create(['primary_section_id' => $otherBattalion->id]);
    $otherStaffUser = User::factory()->create(['cav_id' => $otherStaff->cav_id]);

    // What a viewer gets on `$unit`'s roster, asking for the Admin tab: null when the page is refused outright.
    $admin = function (User $user, $unit) {
        $response = $this->actingAs($user)->get(route('roster.index', ['unit' => $unit->id, 'view' => 'admin']));
        if ($response->status() === 403) {
            return null;
        }
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        return ['tab' => $props['can']['admin'], 'accountability' => $props['accountability'], 'guidon' => $props['can']['editGuidon']];
    };
    $none = ['tab' => false, 'accountability' => null, 'guidon' => false];

    // Nothing for a section or platoon leader, a staff-grant reader, or another battalion's HQ staff.
    expect($admin($this->tree['users']['sl'], $u['host']))->toBe($none)
        ->and($admin($this->tree['users']['pl'], $u['platoon']))->toBe($none)
        ->and($admin($this->tree['users']['grant'], $u['company']))->toBe($none)
        ->and($admin($otherStaffUser, $u['company']))->toBeIn([null, $none]);

    // The accountability options for an Admin, Regimental HQ staff and this battalion's HQ staff.
    foreach ([$this->admin, $regimentStaffUser, $this->bnStaffUser] as $user) {
        $got = $admin($user, $u['platoon']);
        expect($got['tab'])->toBeTrue()
            ->and($got['accountability']['canEdit'])->toBeTrue()
            ->and($got['accountability']['unitId'])->toBe($u['platoon']->id);
    }

    // A company commander gets the tab for the guidon only.
    expect($admin($this->tree['users']['co'], $u['company']))->toBe(['tab' => true, 'accountability' => null, 'guidon' => true]);

    // A view-as preview gets no write UI, and the write is refused.
    $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $u['company']->id])->assertSessionHasNoErrors();
    expect($admin($this->admin, $u['platoon']))->toBe($none);
    $this->actingAs($this->admin)->postJson("/roster/{$u['platoon']->id}/accountability", ['options' => [AccountabilityRules::FTX]])->assertForbidden();
});

it('lets the Admin tab of a company or platoon set the platoons and sections under it, only for those who may', function () {
    $u = $this->tree['units'];
    $units = app(UnitRepository::class);
    $otherBattalion = $units->create(UnitType::Battalion, '2nd Battalion', '2-7', $u['regiment']->id);
    $otherStaff = Trooper::factory()->create(['primary_section_id' => $otherBattalion->id]);
    $otherStaffUser = User::factory()->create(['cav_id' => $otherStaff->cav_id]);
    $children = function (User $user, $unit) {
        $response = $this->actingAs($user)->get(route('roster.index', ['unit' => $unit->id, 'view' => 'admin']));

        return $response->status() === 403 ? null : $response->assertOk()->viewData('page')['props']['accountabilityUnits'];
    };
    $empty = ['platoons' => [], 'sections' => []];

    // A company's tab: its platoon and both sections, each editable, with the right heading data.
    $got = $children($this->bnStaffUser, $u['company']);
    expect(array_column($got['platoons'], 'unitId'))->toBe([$u['platoon']->id])
        ->and(array_column($got['sections'], 'unitId'))->toBe([$u['host']->id, $u['other']->id])
        ->and(array_unique(array_column([...$got['platoons'], ...$got['sections']], 'canEdit')))->toBe([true])
        ->and($got['sections'][0]['kind'])->toBe('makeup')
        ->and($got['platoons'][0]['label'])->toBe('1/A/1-7');

    // A platoon's tab: its sections only. A section's tab has nothing under it.
    expect($children($this->admin, $u['platoon']))->toMatchArray(['platoons' => []])
        ->and(array_column($children($this->admin, $u['platoon'])['sections'], 'unitId'))->toBe([$u['host']->id, $u['other']->id])
        ->and($children($this->admin, $u['host']))->toBe($empty);

    // No child data for leaders who may not change it, nor another battalion's HQ staff.
    foreach (['sl', 'pl', 'co', 'grant', 'psg'] as $who) {
        expect($children($this->tree['users'][$who], $u['company']))->toBeIn([null, $empty]);
    }
    expect($children($otherStaffUser, $u['company']))->toBeIn([null, $empty]);

    // Each child saves through its own endpoint; outside the viewer's rights it is 403 with no audit row.
    $this->actingAs($this->bnStaffUser)->postJson("/roster/{$u['other']->id}/accountability", ['options' => [AccountabilityRules::SP_OWN_UNIT]])
        ->assertOk()->assertJsonPath('accountability.unitId', $u['other']->id);
    $audited = AuditLogEntry::query()->where('action', SetAccountabilityOptions::ACTION)->count();
    $this->actingAs($otherStaffUser)->postJson("/roster/{$u['host']->id}/accountability", ['options' => [AccountabilityRules::SP_OWN_UNIT]])->assertForbidden();
    $this->actingAs($this->tree['users']['co'])->postJson("/roster/{$u['platoon']->id}/accountability", ['options' => [AccountabilityRules::FTX]])->assertForbidden();
    expect(AuditLogEntry::query()->where('action', SetAccountabilityOptions::ACTION)->count())->toBe($audited);
});

it('reads the tree and the stored options once for all the child panels', function () {
    $u = $this->tree['units'];
    $units = app(UnitRepository::class);
    $count = function () use ($u) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(AccountabilityRules::class)->children($this->bnStaffUser, $u['company']);

        return count(DB::getQueryLog());
    };

    $before = $count();
    foreach (range(3, 8) as $n) {
        $units->create(UnitType::Section, "{$n}th Section", "{$n}/1/A/1-7", $u['platoon']->id);
    }

    expect($count())->toBe($before);
});

it('refuses the accountability write to anyone without the right', function () {
    $u = $this->tree['units'];

    foreach (['sl', 'pl', 'co', 'grant', 'psg'] as $who) {
        $this->actingAs($this->tree['users'][$who])->postJson("/roster/{$u['platoon']->id}/accountability", ['options' => [AccountabilityRules::FTX]])->assertForbidden();
    }
    expect(AuditLogEntry::query()->where('action', SetAccountabilityOptions::ACTION)->count())->toBe(0);
});

it('lists what the units under a battalion use on the org structure page', function () {
    $u = $this->tree['units'];
    ($this->set)($this->bnStaffUser, $u['company'], [AccountabilityRules::SP_OWN_UNIT]);

    $this->actingAs($this->tree['users']['sl'])->get(route('settings.org-structure'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where("battalionAccountability.{$u['battalion']->id}.type", 'battalion')
            ->where("battalionAccountability.{$u['battalion']->id}.canEdit", false)
            ->where("battalionAccountability.{$u['battalion']->id}.units", fn ($units) => collect($units)->firstWhere('id', $u['company']->id) === [
                'id' => $u['company']->id, 'label' => 'A/1-7', 'type' => 'company', 'options' => [AccountabilityRules::SP_OWN_UNIT], 'source' => 'own',
            ] && collect($units)->firstWhere('id', $u['host']->id)['source'] === 'default'
                && collect($units)->pluck('type')->unique()->sort()->values()->all() === ['company', 'platoon', 'section']));
});

<?php

use App\Models\FireteamAssignment;
use App\Models\FireteamOrder;
use App\Models\FireteamRole;
use App\Models\FireteamSetting;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\FireteamRepository;
use Illuminate\Database\QueryException;

// A section's unofficial fireteams and how a unit's rollcall post shows them (portal-owned, AD-22).

beforeEach(function () {
    $this->repo = app(FireteamRepository::class);
    $this->section = Unit::factory()->create(['type' => UnitType::Section]);
    [$this->ftl, $this->member, $this->other] = Trooper::factory()->count(3)->create()->all();
});

it('makes a fireteam leader and puts a trooper in their fireteam, one row per trooper', function () {
    $leader = $this->repo->makeLeader($this->section->id, $this->ftl->id);
    $member = $this->repo->makeMember($this->section->id, $this->member->id, $this->ftl->id);

    expect($leader->role)->toBe(FireteamRole::Leader)->and($leader->leader_trooper_id)->toBeNull()
        ->and($member->role)->toBe(FireteamRole::Member)->and($member->leader_trooper_id)->toBe($this->ftl->id)
        ->and($this->repo->assignmentsIn($this->section->id)->keys()->sort()->values()->all())
        ->toBe(collect([$this->ftl->id, $this->member->id])->sort()->values()->all());
});

it('moves a member who becomes a leader out of their old fireteam', function () {
    $this->repo->makeLeader($this->section->id, $this->ftl->id);
    $this->repo->makeMember($this->section->id, $this->member->id, $this->ftl->id);

    $this->repo->makeLeader($this->section->id, $this->member->id);

    expect(FireteamAssignment::query()->count())->toBe(2)
        ->and($this->repo->assignmentsIn($this->section->id)[$this->member->id]->leader_trooper_id)->toBeNull();
});

it('takes a leader\'s members out with them, and leaves other sections alone', function () {
    $elsewhere = Unit::factory()->create(['type' => UnitType::Section]);
    $this->repo->makeLeader($this->section->id, $this->ftl->id);
    $this->repo->makeMember($this->section->id, $this->member->id, $this->ftl->id);
    $this->repo->makeLeader($elsewhere->id, $this->other->id);

    $removed = $this->repo->remove($this->section->id, $this->ftl->id);

    expect($removed->trooper_id)->toBe($this->ftl->id)
        ->and($this->repo->assignmentsIn($this->section->id))->toBeEmpty()
        ->and($this->repo->assignmentsIn($elsewhere->id))->toHaveCount(1)
        ->and($this->repo->remove($this->section->id, $this->ftl->id))->toBeNull();
});

it('refuses a second row for the same trooper in a section', function () {
    FireteamAssignment::query()->create(['section_id' => $this->section->id, 'trooper_id' => $this->ftl->id, 'role' => 'ftl']);

    expect(fn () => FireteamAssignment::query()->create(['section_id' => $this->section->id, 'trooper_id' => $this->ftl->id, 'role' => 'ftl']))
        ->toThrow(QueryException::class);
});

it('writes only the setting values it is given', function () {
    expect($this->repo->settingFor($this->section->id))->toBeNull();

    $this->repo->updateSetting($this->section->id, ['show_fireteams' => true]);
    $this->repo->updateSetting($this->section->id, ['fireteam_order' => FireteamOrder::ByFireteam]);
    $setting = $this->repo->settingFor($this->section->id);

    expect($setting->show_fireteams)->toBeTrue()
        ->and($setting->fireteam_order)->toBe(FireteamOrder::ByFireteam)
        ->and(FireteamSetting::query()->count())->toBe(1);

    $this->repo->updateSetting($this->section->id, ['fireteam_order' => null]);

    expect($this->repo->settingFor($this->section->id)->fireteam_order)->toBeNull()
        ->and($this->repo->settingFor($this->section->id)->show_fireteams)->toBeTrue();
});

it('drops a unit\'s fireteams and setting with the unit', function () {
    $this->repo->makeLeader($this->section->id, $this->ftl->id);
    $this->repo->updateSetting($this->section->id, ['show_fireteams' => true]);

    $this->section->delete();

    expect(FireteamAssignment::query()->count())->toBe(0)->and(FireteamSetting::query()->count())->toBe(0);
});

describe('the effective setting of a section', function () {
    $setting = fn (?bool $show, ?FireteamOrder $order) => new FireteamSetting(['show_fireteams' => $show, 'fireteam_order' => $order]);

    it('defaults to FTLs hidden and all FTLs above the rest', function () use ($setting) {
        expect(FireteamSetting::effective(null, null))->toBe(['show' => false, 'order' => FireteamOrder::LeadersFirst, 'slAslLead' => false])
            ->and(FireteamSetting::effective($setting(null, null), $setting(null, null)))->toBe(['show' => false, 'order' => FireteamOrder::LeadersFirst, 'slAslLead' => false]);
    });

    it('takes the section\'s choice where the company leaves it to them', function () use ($setting) {
        expect(FireteamSetting::effective(null, $setting(true, FireteamOrder::ByFireteam)))->toBe(['show' => true, 'order' => FireteamOrder::ByFireteam, 'slAslLead' => false])
            ->and(FireteamSetting::effective($setting(null, null), $setting(true, FireteamOrder::ByFireteam)))->toBe(['show' => true, 'order' => FireteamOrder::ByFireteam, 'slAslLead' => false]);
    });

    it('lets the company\'s policy override the section, value by value', function () use ($setting) {
        expect(FireteamSetting::effective($setting(false, null), $setting(true, FireteamOrder::ByFireteam)))->toBe(['show' => false, 'order' => FireteamOrder::ByFireteam, 'slAslLead' => false])
            ->and(FireteamSetting::effective($setting(null, FireteamOrder::LeadersFirst), $setting(true, FireteamOrder::ByFireteam)))->toBe(['show' => true, 'order' => FireteamOrder::LeadersFirst, 'slAslLead' => false])
            ->and(FireteamSetting::effective($setting(false, FireteamOrder::LeadersFirst), null))->toBe(['show' => false, 'order' => FireteamOrder::LeadersFirst, 'slAslLead' => false]);
    });

    it('lets only the company say whether the SL and ASL may lead a fireteam, off unless it does', function () {
        $lead = fn (?bool $value) => new FireteamSetting(['sl_asl_lead_fireteams' => $value]);

        expect(FireteamSetting::effective($lead(true), null)['slAslLead'])->toBeTrue()
            ->and(FireteamSetting::effective($lead(false), $lead(true))['slAslLead'])->toBeFalse()
            ->and(FireteamSetting::effective(null, $lead(true))['slAslLead'])->toBeFalse()
            ->and(FireteamSetting::effective($lead(null), null)['slAslLead'])->toBeFalse();
    });
});

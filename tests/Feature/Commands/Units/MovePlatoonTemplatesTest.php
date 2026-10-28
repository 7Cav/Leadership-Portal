<?php

use App\Commands\Templates\SetCompanyTemplatePolicy;
use App\Commands\Units\MoveUnit;
use App\Models\ArtifactType;
use App\Models\AuditLogEntry;
use App\Models\FireteamOrder;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\FireteamRepository;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitActingLeadRepository;
use App\Repositories\UnitRepository;
use App\Rollcall\TemplateResolver;
use App\Rollcall\TemplateSource;
use Carbon\CarbonImmutable;

// #378 / AD-16, AD-17: a platoon moved to another company keeps its settings and templates. The templates its sections
// used are carried along and stay available there; fireteam settings, labels and acting leads stay on their units; the
// move and what came along are audited. Where the new company's battalion sets its templates (#376), the carried ones
// are kept but not used, and the platoon is told which template it now uses.
// Tree: 7CAV ─ 1-7 ─┬─ A/1-7 ─ 1/A/1-7 ─┬─ 1/1/A/1-7 (picks Training)
//                   │                  └─ 2/1/A/1-7 (company default: Standard)
//                   └─ B/1-7 ─ 1/B/1-7

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->alpha = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->bravo = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->alpha->id);
    $this->bravoPlatoon = $units->create(UnitType::Platoon, '1st Platoon', '1/B/1-7', $this->bravo->id);
    $this->picker = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->follower = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->platoon->id);

    $this->admin = User::factory()->admin()->create();
    $this->repo = app(TemplateVersionRepository::class);
    $this->type = ArtifactType::RollcallBbcode;
    $this->standard = $this->repo->author($this->alpha->id, $this->type, 'alpha standard', $this->admin->id)->template;
    $this->training = $this->repo->createTemplate($this->alpha->id, $this->type, 'Training', 'alpha training', $this->admin->id);
    $this->unused = $this->repo->createTemplate($this->alpha->id, $this->type, 'Unused', 'alpha unused', $this->admin->id);
    $this->bravoOwn = $this->repo->author($this->bravo->id, $this->type, 'bravo standard', $this->admin->id)->template;
    $this->repo->choose($this->picker, $this->training, $this->admin->id);

    $this->resolver = app(TemplateResolver::class);
    $this->move = fn (Unit $unit, Unit $to) => app(MoveUnit::class)->handle($this->admin, Unit::query()->find($unit->id), $to->id);
});

it('carries the templates its sections used, so they stay available in the new company', function () {
    ($this->move)($this->platoon, $this->bravo);

    $picked = $this->resolver->forUnit($this->picker->refresh());
    $following = $this->resolver->forUnit($this->follower->refresh());

    expect($picked->version->body)->toBe('alpha training')
        ->and($picked->source)->toBe(TemplateSource::Picked)
        // A section that followed its old company's default now follows the new company's, with the old one to pick.
        ->and($following->version->body)->toBe('bravo standard')
        ->and($this->resolver->optionsFor($this->follower)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->repo->regimentDefault($this->type)->id, $this->bravoOwn->id, $this->standard->id, $this->training->id])->sort()->values()->all())
        // Only what its sections used: an Alpha template nobody in the platoon used stays behind.
        ->and($this->resolver->optionsFor($this->follower)->pluck('id')->all())->not->toContain($this->unused->id)
        ->and(collect($this->resolver->pickerFor($this->follower)['options'])->where('isCarried', true)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->standard->id, $this->training->id])->sort()->values()->all())
        ->and($this->resolver->pickerFor($this->follower)['moved'])->toBe(['from' => ['A/1-7'], 'usable' => true, 'using' => 'Company template']);
});

it('keeps fireteam settings, labels and acting leads on the platoon and its sections', function () {
    $fireteams = app(FireteamRepository::class);
    $fireteams->updateSetting($this->picker->id, ['show_fireteams' => true, 'fireteam_order' => FireteamOrder::ByFireteam]);
    app(UnitRepository::class)->setLabel($this->platoon, 'WARDOGS');
    $lead = Trooper::factory()->create();
    app(UnitActingLeadRepository::class)->assign($this->platoon->id, $lead->id, $this->admin->id);

    ($this->move)($this->platoon, $this->bravo);

    expect($fireteams->settingFor($this->picker->id)->show_fireteams)->toBeTrue()
        ->and($fireteams->settingFor($this->picker->id)->fireteam_order)->toBe(FireteamOrder::ByFireteam)
        ->and(Unit::query()->find($this->platoon->id)->label)->toBe('WARDOGS')
        ->and(app(UnitActingLeadRepository::class)->forUnit($this->platoon->id)->trooper_id)->toBe($lead->id)
        ->and($this->repo->choiceFor($this->picker->id, $this->type)->template_id)->toBe($this->training->id);
});

it('audits the move and what came along in one transaction', function () {
    ($this->move)($this->platoon, $this->bravo);

    $entries = AuditLogEntry::query()->orderBy('id')->get();

    expect($entries->pluck('action')->all())->toBe(['unit.settings_carried', 'unit.moved'])
        ->and($entries->pluck('target_type')->unique()->all())->toBe(['Unit'])
        ->and((int) $entries[0]->target_id)->toBe($this->platoon->id)
        ->and($entries[0]->actor_id)->toBe($this->admin->id)
        ->and(DB::table('template_carries')->where('unit_id', $this->platoon->id)->pluck('template_id')->sort()->values()->all())
        ->toBe(collect([$this->standard->id, $this->training->id])->sort()->values()->all())
        ->and(DB::table('template_carries')->distinct()->pluck('from_company_id')->all())->toBe([$this->alpha->id]);
});

it('carries nothing and logs only the move for a move inside one company', function () {
    $second = app(UnitRepository::class)->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->alpha->id);

    ($this->move)($this->picker, $second);

    expect(AuditLogEntry::query()->pluck('action')->all())->toBe(['unit.moved'])
        ->and(DB::table('template_carries')->count())->toBe(0);
});

it('carries a section moved on its own to another company', function () {
    ($this->move)($this->picker, $this->bravoPlatoon);

    expect($this->resolver->forUnit($this->picker->refresh())->version->body)->toBe('alpha training')
        ->and(DB::table('template_carries')->where('unit_id', $this->picker->id)->count())->toBe(1);
});

it('does not carry a template twice when the platoon moves back and forth, adding only what it newly used', function () {
    ($this->move)($this->platoon, $this->bravo);
    ($this->move)($this->platoon, $this->alpha);
    ($this->move)($this->platoon, $this->bravo);

    // Alpha's two, and Bravo's own, which its second section used while there and took back to Alpha.
    expect(DB::table('template_carries')->where('unit_id', $this->platoon->id)->pluck('template_id')->sort()->values()->all())
        ->toBe(collect([$this->standard->id, $this->training->id, $this->bravoOwn->id])->sort()->values()->all())
        ->and($this->resolver->forUnit($this->picker->refresh())->version->body)->toBe('alpha training');
});

describe('when the new company\'s battalion sets its templates (#376)', function () {
    beforeEach(function () {
        app(SetCompanyTemplatePolicy::class)->handle($this->admin, $this->bravo, false);
        ($this->move)($this->platoon, $this->bravo);
    });

    it('keeps the carried templates but does not use them, so the sections follow the new company', function () {
        expect($this->resolver->forUnit($this->picker->refresh())->version->body)->toBe('bravo standard')
            ->and($this->resolver->optionsFor($this->picker)->pluck('id')->all())->not->toContain($this->training->id)
            ->and(DB::table('template_carries')->where('unit_id', $this->platoon->id)->count())->toBe(2);
    });

    it('tells the platoon which template it now uses, on its rollcall', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));

        $this->actingAs($this->admin)->get(route('rollcall.show', $this->picker->id))->assertOk()->assertInertia(fn ($page) => $page
            ->where('templatePicker.moved', ['from' => ['A/1-7'], 'usable' => false, 'using' => 'Company template'])
            ->where('template.body', 'bravo standard'));
    });

    it('lets them be used once the battalion hands template editing back', function () {
        app(SetCompanyTemplatePolicy::class)->handle($this->admin, $this->bravo, true);

        expect($this->resolver->forUnit($this->picker->refresh())->version->body)->toBe('alpha training');
    });
});

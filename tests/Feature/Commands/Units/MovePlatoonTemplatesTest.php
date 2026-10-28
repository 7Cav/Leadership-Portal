<?php

use App\Commands\Templates\ActivateTemplateVersion;
use App\Commands\Templates\AuthorTemplateVersion;
use App\Commands\Templates\SwitchTemplate;
use App\Commands\Templates\TemplateAuthority;
use App\Commands\Units\MoveUnit;
use App\Models\ArtifactType;
use App\Models\AuditLogEntry;
use App\Models\FireteamOrder;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitTemplateChoice;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\FireteamRepository;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitActingLeadRepository;
use App\Repositories\UnitRepository;
use App\Rollcall\TemplateResolver;
use App\Rollcall\TemplateSource;
use Carbon\CarbonImmutable;

// #378 / AD-16, AD-17: a platoon moved to another company keeps its settings and what its posts render. The templates
// its sections used are copied into the new company, which owns and manages the copies, and the sections' picks move to
// them, so the old company's later edits never reach them; fireteam settings, labels and acting leads stay on their
// units; the move and what was copied are audited. When the new company switches a copy off, the platoon is told which
// template it now uses. A section's own templates move with it and are never copied.
// Tree: 7CAV ─ 1-7 ─┬─ A/1-7 ─ 1/A/1-7 ─┬─ 1/1/A/1-7 (picks Training)
//                   │                  └─ 2/1/A/1-7 (company default: Company template)
//                   └─ B/1-7 ─ 1/B/1-7 ─ 1/1/B/1-7 (company default: Company template)

/** A user whose synced billet commands `$unit`. */
function moveCommander(Unit $unit, int $positionId): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $positionId]);
    $trooper = Trooper::factory()->create(['cav_position_id' => $positionId]);

    return User::factory()->create(['cav_id' => $trooper->cav_id]);
}

/** The copy of `$source` the move made in `$company`, or null. */
function copyOf(Template $source, Unit $company): ?Template
{
    return Template::query()->with('activeVersion')->where('company_id', $company->id)->where('copied_from_id', $source->id)->first();
}

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
    $this->bravoSection = $units->create(UnitType::Section, '1st Section', '1/1/B/1-7', $this->bravoPlatoon->id);

    $this->admin = User::factory()->admin()->create();
    $this->author = User::factory()->create();
    $this->repo = app(TemplateVersionRepository::class);
    $this->type = ArtifactType::RollcallBbcode;
    $this->standard = $this->repo->author($this->alpha->id, $this->type, 'alpha standard', $this->admin->id)->template;
    $this->training = $this->repo->createTemplate($this->alpha->id, $this->type, 'Training', 'alpha training', $this->author->id);
    $this->unused = $this->repo->createTemplate($this->alpha->id, $this->type, 'Unused', 'alpha unused', $this->admin->id);
    $this->bravoOwn = $this->repo->author($this->bravo->id, $this->type, 'bravo standard', $this->admin->id)->template;
    $this->repo->choose($this->picker, $this->training, $this->admin->id);

    $this->resolver = app(TemplateResolver::class);
    $this->move = fn (Unit $unit, Unit $to) => app(MoveUnit::class)->handle($this->admin, Unit::query()->find($unit->id), $to->id);
});

it('copies the templates its sections used into the new company and points their picks at the copies', function () {
    ($this->move)($this->platoon, $this->bravo);

    $training = copyOf($this->training, $this->bravo);
    $standard = copyOf($this->standard, $this->bravo);
    $picked = $this->resolver->forUnit($this->picker->refresh());
    $following = $this->resolver->forUnit($this->follower->refresh());

    expect($training->name)->toBe('Training (from A/1-7)')
        ->and($training->enabled)->toBeTrue()
        ->and($training->created_by)->toBe($this->admin->id)
        // One version: the one that was active, with its body and its author.
        ->and(TemplateVersion::query()->where('template_id', $training->id)->count())->toBe(1)
        ->and($training->activeVersion->version)->toBe(1)
        ->and($training->activeVersion->body)->toBe('alpha training')
        ->and($training->activeVersion->created_by)->toBe($this->author->id)
        ->and($training->activeVersion->company_id)->toBe($this->bravo->id)
        ->and($standard->name)->toBe('Company template (from A/1-7)')
        // Both sections render what they did, now from Bravo's copies.
        ->and($picked->template->id)->toBe($training->id)
        ->and($picked->version->body)->toBe('alpha training')
        ->and($picked->source)->toBe(TemplateSource::Picked)
        ->and($following->template->id)->toBe($standard->id)
        ->and($following->version->body)->toBe('alpha standard')
        // Only what its sections used: an Alpha template nobody in the platoon used stays behind.
        ->and(copyOf($this->unused, $this->bravo))->toBeNull()
        ->and(Template::query()->where('company_id', $this->bravo->id)->count())->toBe(3)
        // Alpha keeps its own, and Bravo's other sections still follow Bravo's default.
        ->and(Template::query()->where('company_id', $this->alpha->id)->count())->toBe(3)
        ->and($this->resolver->forUnit($this->bravoSection)->version->body)->toBe('bravo standard')
        ->and($this->repo->activeFor($this->bravo->id, $this->type)->template_id)->toBe($this->bravoOwn->id)
        ->and($this->resolver->pickerFor($this->picker)['moved'])->toBeNull();
});

it('never makes a copy the active template of a company that had none, so its other sections keep theirs', function () {
    $units = app(UnitRepository::class);
    $charlie = $units->create(UnitType::Company, 'Charlie Company', 'C/1-7', $this->battalion->id);
    $charlieSection = $units->create(UnitType::Section, '1st Section', '1/1/C/1-7', $units->create(UnitType::Platoon, '1st Platoon', '1/C/1-7', $charlie->id)->id);

    ($this->move)($this->platoon, $charlie);

    expect($this->repo->activeFor($charlie->id, $this->type))->toBeNull()
        ->and($this->resolver->forUnit($charlieSection)->source)->toBe(TemplateSource::RegimentDefault)
        ->and($this->resolver->forUnit($this->follower->refresh())->version->body)->toBe('alpha standard');
});

it('keeps the moved sections on their copies whatever the old company does next', function () {
    ($this->move)($this->platoon, $this->bravo);
    $alphaCo = moveCommander($this->alpha, 401);
    $first = $this->training->activeVersion;

    app(AuthorTemplateVersion::class)->handle($alphaCo, $this->training->refresh(), 'alpha training, edited', 'new week');
    app(ActivateTemplateVersion::class)->handle($alphaCo, $first, 'rolled back');
    app(AuthorTemplateVersion::class)->handle($alphaCo, $this->training->refresh(), 'alpha training, again', 'new week');
    app(SwitchTemplate::class)->handle($alphaCo, $this->training->refresh(), false);
    app(AuthorTemplateVersion::class)->handle($alphaCo, $this->standard->refresh(), 'alpha standard, edited', 'new week');

    expect($this->resolver->forUnit($this->picker->refresh())->version->body)->toBe('alpha training')
        ->and($this->resolver->forUnit($this->follower->refresh())->version->body)->toBe('alpha standard')
        // Alpha's command has no hold on Bravo's copy.
        ->and(app(TemplateAuthority::class)->manages($alphaCo, copyOf($this->training, $this->bravo)))->toBeFalse();
});

it('lets the new company\'s command author and switch the copies as its own', function () {
    ($this->move)($this->platoon, $this->bravo);
    $bravoCo = moveCommander($this->bravo, 402);
    $copy = copyOf($this->training, $this->bravo);

    expect(app(TemplateAuthority::class)->manages($bravoCo, $copy))->toBeTrue();

    app(AuthorTemplateVersion::class)->handle($bravoCo, $copy, 'bravo training', 'own wording');

    expect($this->resolver->forUnit($this->picker->refresh())->version->body)->toBe('bravo training');

    app(SwitchTemplate::class)->handle($bravoCo, $copy->refresh(), false);

    // Switched off, the section follows Bravo's default.
    expect($this->resolver->forUnit($this->picker->refresh())->version->body)->toBe('bravo standard');
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
        ->and(app(UnitActingLeadRepository::class)->forUnit($this->platoon->id)->trooper_id)->toBe($lead->id);
});

it('audits the move and what was copied in one transaction', function () {
    ($this->move)($this->platoon, $this->bravo);

    $entries = AuditLogEntry::query()->orderBy('id')->get();

    expect($entries->pluck('action')->all())->toBe(['unit.settings_carried', 'unit.moved'])
        ->and($entries->pluck('target_type')->unique()->all())->toBe(['Unit'])
        ->and((int) $entries[0]->target_id)->toBe($this->platoon->id)
        ->and($entries[0]->actor_id)->toBe($this->admin->id)
        ->and(Template::query()->where('company_id', $this->bravo->id)->whereNotNull('copied_from_id')->pluck('copied_from_id')->sort()->values()->all())
        ->toBe(collect([$this->standard->id, $this->training->id])->sort()->values()->all())
        ->and(UnitTemplateChoice::query()->whereIn('unit_id', [$this->picker->id, $this->follower->id])->pluck('template_id')->sort()->values()->all())
        ->toBe(collect([copyOf($this->standard, $this->bravo)->id, copyOf($this->training, $this->bravo)->id])->sort()->values()->all());
});

it('copies nothing and logs only the move for a move inside one company', function () {
    $second = app(UnitRepository::class)->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->alpha->id);

    ($this->move)($this->picker, $second);

    expect(AuditLogEntry::query()->pluck('action')->all())->toBe(['unit.moved'])
        ->and(Template::query()->whereNotNull('copied_from_id')->count())->toBe(0)
        ->and($this->repo->choiceFor($this->picker->id, $this->type)->template_id)->toBe($this->training->id);
});

it('copies for a section moved on its own to another company', function () {
    ($this->move)($this->picker, $this->bravoPlatoon);

    expect($this->resolver->forUnit($this->picker->refresh())->template->id)->toBe(copyOf($this->training, $this->bravo)->id)
        ->and(Template::query()->whereNotNull('copied_from_id')->count())->toBe(1);
});

it('copies nothing for a section on the regiment default, which it keeps', function () {
    $default = $this->repo->regimentDefault($this->type);
    $this->repo->choose($this->picker, $default, $this->admin->id);

    ($this->move)($this->picker, $this->bravoPlatoon);

    expect($this->resolver->forUnit($this->picker->refresh())->template->id)->toBe($default->id)
        ->and(Template::query()->whereNotNull('copied_from_id')->count())->toBe(0);
});

it('numbers a copy whose name the new company holds already, and keeps a long name within the limit', function () {
    $this->repo->createTemplate($this->bravo->id, $this->type, 'training (from a/1-7)', 'bravo own', $this->admin->id);
    $long = $this->repo->createTemplate($this->alpha->id, $this->type, str_repeat('L', 60), 'alpha long', $this->admin->id);
    $this->repo->choose($this->follower, $long, $this->admin->id);

    ($this->move)($this->platoon, $this->bravo);

    $longCopy = copyOf($long, $this->bravo)->name;

    expect(copyOf($this->training, $this->bravo)->name)->toBe('Training (from A/1-7) (2)')
        ->and(mb_strlen($longCopy))->toBeLessThanOrEqual(60)
        ->and($longCopy)->toEndWith(' (from A/1-7)');
});

it('copies again on the way back, so each company owns what its sections render', function () {
    ($this->move)($this->platoon, $this->bravo);
    ($this->move)($this->platoon, $this->alpha);

    $back = Template::query()->where('company_id', $this->alpha->id)->where('copied_from_id', copyOf($this->training, $this->bravo)->id)->first();

    expect($back->name)->toBe('Training (from A/1-7) (from B/1-7)')
        ->and($this->resolver->forUnit($this->picker->refresh())->template->id)->toBe($back->id)
        ->and($this->resolver->forUnit($this->picker)->version->body)->toBe('alpha training');
});

describe('when the new company switches a copy off', function () {
    beforeEach(function () {
        ($this->move)($this->platoon, $this->bravo);
        app(SwitchTemplate::class)->handle($this->admin, copyOf($this->training, $this->bravo), false);
    });

    it('tells the platoon which template it now uses, on its rollcall', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));

        $this->actingAs($this->admin)->get(route('rollcall.show', $this->picker->id))->assertOk()->assertInertia(fn ($page) => $page
            ->where('templatePicker.moved', ['from' => 'A/1-7', 'copy' => 'Training (from A/1-7)', 'using' => 'Company template'])
            ->where('template.body', 'bravo standard'));
    });

    it('lets the company command switch it back on, which the section then uses', function () {
        app(SwitchTemplate::class)->handle(moveCommander($this->bravo, 404), copyOf($this->training, $this->bravo), true);

        expect($this->resolver->forUnit($this->picker->refresh())->version->body)->toBe('alpha training')
            ->and($this->resolver->pickerFor($this->picker)['moved'])->toBeNull();
    });
});

describe("a section's own templates", function () {
    beforeEach(function () {
        $this->own = $this->repo->createTemplate($this->picker->id, $this->type, 'Ours', 'our own', $this->author->id);
        $this->repo->choose($this->picker, $this->own, $this->admin->id);
    });

    it('move with the section to another company, still picked and used, and are not copied', function () {
        ($this->move)($this->platoon, $this->bravo);

        $resolved = $this->resolver->forUnit($this->picker->refresh());

        expect($resolved->template->id)->toBe($this->own->id)
            ->and($resolved->version->body)->toBe('our own')
            ->and($resolved->source)->toBe(TemplateSource::Picked)
            ->and($this->own->refresh()->section_id)->toBe($this->picker->id)
            ->and(Template::query()->where('copied_from_id', $this->own->id)->exists())->toBeFalse()
            // The other section still had the company default, which is copied as before, and only that one.
            ->and(Template::query()->where('company_id', $this->bravo->id)->whereNotNull('copied_from_id')->pluck('copied_from_id')->all())->toBe([$this->standard->id])
            ->and($this->resolver->optionsFor($this->picker)->pluck('id')->all())->toContain($this->own->id);
    });

    it('move with a section moved on its own, and with a move to another platoon of the same company', function () {
        $second = app(UnitRepository::class)->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->alpha->id);

        ($this->move)($this->picker, $second);
        expect($this->resolver->forUnit($this->picker->refresh())->template->id)->toBe($this->own->id);

        ($this->move)($this->picker, $this->bravoPlatoon);
        expect($this->resolver->forUnit($this->picker->refresh())->template->id)->toBe($this->own->id)
            ->and(Template::query()->whereNotNull('copied_from_id')->count())->toBe(0);
    });

    it('stay the section\'s to manage after the move: its new company command manages them, the old one does not', function () {
        ($this->move)($this->platoon, $this->bravo);
        $authority = app(TemplateAuthority::class);

        expect($authority->manages(moveCommander($this->bravo, 405), $this->own->refresh()))->toBeTrue()
            ->and($authority->manages(moveCommander($this->alpha, 406), $this->own))->toBeFalse()
            ->and($authority->manages(moveCommander($this->picker, 407), $this->own))->toBeTrue();
    });
});

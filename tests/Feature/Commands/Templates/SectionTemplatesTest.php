<?php

use App\Commands\Templates\ActivateTemplateVersion;
use App\Commands\Templates\AuthorTemplateVersion;
use App\Commands\Templates\CreateTemplate;
use App\Commands\Templates\InvalidTemplateException;
use App\Commands\Templates\PickSectionTemplate;
use App\Commands\Templates\SetCompanyTemplate;
use App\Commands\Templates\SwitchTemplate;
use App\Commands\Templates\TemplateAuthority;
use App\Commands\Templates\TemplateForbiddenException;
use App\Commands\Units\DeleteUnit;
use App\Commands\Units\UnitHasDependentsException;
use App\Models\ArtifactType;
use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\Template;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitActingLeadRepository;
use App\Repositories\UnitRepository;
use App\Rollcall\TemplateResolver;
use App\Rollcall\TemplateSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A section keeps named templates of its own, exactly as a company does (AD-8, AD-16, AD-17): whoever commands the
// section manages them, only that section's rollcall may use them, and its leader picks one beside the company's
// switched-on templates and the regiment default. The battalion switch (#376) is gone.
// Tree: 7CAV ─┬─ 1-7 ─┬─ A/1-7 ─ 1/A/1-7 ─┬─ 1/1/A/1-7
//             │       │                  └─ 2/1/A/1-7
//             │       └─ B/1-7
//             └─ 2-7

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->otherBattalion = $units->create(UnitType::Battalion, '2nd Battalion', '2-7', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->otherCompany = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->sibling = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->platoon->id);

    $this->admin = User::factory()->admin()->create();
    $this->sl = sectionCommander($this->section, 501);
    $this->asl = sectionCommander($this->section, 502);
    $this->pl = sectionCommander($this->platoon, 503);
    $this->co = sectionCommander($this->company, 504);
    $this->firstSergeant = sectionCommander($this->company, 505);
    $this->bnCo = sectionCommander($this->battalion, 506);
    $this->rgt = sectionCommander($this->regiment, 507);
    $this->siblingSl = sectionCommander($this->sibling, 508);
    $this->otherCo = sectionCommander($this->otherCompany, 509);
    $this->otherBn = sectionCommander($this->otherBattalion, 510);

    $this->repo = app(TemplateVersionRepository::class);
    $this->resolver = app(TemplateResolver::class);
    $this->type = ArtifactType::RollcallBbcode;
    $this->create = app(CreateTemplate::class);
    $this->companyStandard = $this->create->handle($this->co, $this->company, $this->type, 'Standard', 'company standard', 'why');
});

function sectionCommander(Unit $unit, int $positionId): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $positionId]);
    $trooper = Trooper::factory()->create(['cav_position_id' => $positionId]);

    return User::factory()->create(['cav_id' => $trooper->cav_id]);
}

/** A user leading `$unit` as its acting lead, with no billet of their own. */
function sectionActingLead(Unit $unit): User
{
    $trooper = Trooper::factory()->create();
    app(UnitActingLeadRepository::class)->assign($unit->id, $trooper->id, null);

    return User::factory()->create(['cav_id' => $trooper->cav_id]);
}

describe('who manages a section\'s templates', function () {
    it('lets the section\'s SL and ASL, the command above it, an acting lead and an Admin add, edit and roll one back', function (string $who) {
        $actor = match ($who) {
            'actingSl' => sectionActingLead($this->section),
            'actingPl' => sectionActingLead($this->platoon),
            default => $this->{$who},
        };

        $own = $this->create->handle($actor, $this->section, $this->type, 'Ours', 'one', 'why');
        $edited = app(AuthorTemplateVersion::class)->handle($actor, $own, 'two', 'why');
        app(ActivateTemplateVersion::class)->handle($actor, $this->repo->historyOf($own)->firstWhere('version', 1), 'roll back');

        expect($own->section_id)->toBe($this->section->id)
            ->and($own->company_id)->toBeNull()
            ->and($own->isRegimentDefault())->toBeFalse()
            ->and($edited->version)->toBe(2)
            ->and($edited->section_id)->toBe($this->section->id)
            ->and($this->repo->findTemplate($own->id)->activeVersion->body)->toBe('one')
            ->and(app(TemplateAuthority::class)->manages($actor, $own))->toBeTrue();
    })->with(['sl', 'asl', 'pl', 'co', 'firstSergeant', 'bnCo', 'rgt', 'actingSl', 'actingPl', 'admin']);

    it('refuses another section\'s leader, another company\'s or battalion\'s command and a staff grant, writing nothing', function (string $who) {
        if ($who === 'grant') {
            $this->grant = User::factory()->serving()->create();
            PortalRoleGrant::factory()->create(['user_id' => $this->grant->id, 'unit_id' => $this->section->id]);
        }
        $own = $this->repo->createTemplate($this->section->id, $this->type, 'Ours', 'one', $this->admin->id);
        $before = AuditLogEntry::query()->count();
        $actor = $this->{$who};

        foreach ([
            fn () => $this->create->handle($actor, $this->section, $this->type, 'Theirs', 'body', 'why'),
            fn () => app(AuthorTemplateVersion::class)->handle($actor, $own, 'changed', 'why'),
            fn () => app(ActivateTemplateVersion::class)->handle($actor, $own->activeVersion, 'why'),
        ] as $write) {
            expect($write)->toThrow(TemplateForbiddenException::class, "Only the section's command or an Admin");
        }

        expect(AuditLogEntry::query()->count())->toBe($before)
            ->and($this->repo->templatesOf($this->section->id, $this->type))->toHaveCount(1);
    })->with(['siblingSl', 'otherCo', 'otherBn', 'grant']);

    it('refuses a write during a view-as preview, even an Admin previewing the section leader', function () {
        $own = $this->repo->createTemplate($this->section->id, $this->type, 'Ours', 'one', $this->admin->id);
        $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section->id])->assertSessionHasNoErrors();

        $this->post('/settings/templates/new', ['section_id' => $this->section->id, 'name' => 'X', 'body' => 'b', 'reason' => 'r']);
        $this->post('/settings/templates', ['template_id' => $own->id, 'body' => 'two', 'reason' => 'why']);

        expect($this->repo->historyOf($own))->toHaveCount(1)
            ->and($this->repo->templatesOf($this->section->id, $this->type))->toHaveCount(1);
    });

    it('refuses a unit that is neither a company nor a section', function () {
        expect(fn () => $this->create->handle($this->admin, $this->platoon, $this->type, 'X', 'body', 'why'))
            ->toThrow(InvalidTemplateException::class, 'belongs to a company or a section');
    });

    it('leaves the company command managing its own company\'s templates, with no battalion switch', function () {
        expect(app(TemplateAuthority::class)->manages($this->co, $this->companyStandard))->toBeTrue()
            ->and(app(TemplateAuthority::class)->manages($this->sl, $this->companyStandard))->toBeFalse()
            ->and(Schema::hasTable('company_template_policies'))->toBeFalse()
            ->and(class_exists('App\\Commands\\Templates\\SetCompanyTemplatePolicy'))->toBeFalse()
            ->and(class_exists('App\\Models\\CompanyTemplatePolicy'))->toBeFalse();
    });
});

describe('a section\'s template, as a company\'s', function () {
    it('is checked by the same rules: a name, unique within the section, and a well-formed body', function (Closure $call, string $message) {
        $this->create->handle($this->sl, $this->section, $this->type, 'Ours', 'body', 'why');

        expect(fn () => $call($this))->toThrow(InvalidTemplateException::class, $message);
        expect($this->repo->templatesOf($this->section->id, $this->type))->toHaveCount(1);
    })->with([
        'a blank name' => [fn ($t) => $t->create->handle($t->sl, $t->section, $t->type, '  ', 'body', 'why'), 'needs a name'],
        'a taken name' => [fn ($t) => $t->create->handle($t->sl, $t->section, $t->type, ' ours ', 'body', 'why'), 'This section already has'],
        'a bad body' => [fn ($t) => $t->create->handle($t->sl, $t->section, $t->type, 'New', '{{colour}}', 'why'), 'Unknown name'],
        'a bad edit' => [fn ($t) => app(AuthorTemplateVersion::class)->handle($t->sl, $t->repo->templatesOf($t->section->id, $t->type)->first(), '{{colour}}', 'why'), 'Unknown name'],
    ]);

    it('may share a name with a company template and another section\'s', function () {
        $ours = $this->create->handle($this->sl, $this->section, $this->type, 'Standard', 'ours', 'why');
        $theirs = $this->create->handle($this->siblingSl, $this->sibling, $this->type, 'Standard', 'theirs', 'why');

        expect($ours->name)->toBe('Standard')->and($theirs->name)->toBe('Standard');
    });

    it('is audited like a company\'s: created, authored and activated, each with its reason', function () {
        $own = $this->create->handle($this->sl, $this->section, $this->type, 'Ours', 'one', 'our own layout');
        app(AuthorTemplateVersion::class)->handle($this->sl, $own, 'two', 'tidied');
        app(ActivateTemplateVersion::class)->handle($this->sl, $this->repo->historyOf($own)->firstWhere('version', 1), 'went back');

        $entries = AuditLogEntry::query()->where('actor_id', $this->sl->id)->orderBy('id')->get();

        expect($entries->pluck('action')->all())->toBe(['template.created', 'template_version.authored', 'template_version.activated'])
            ->and($entries->pluck('target_type')->all())->toBe(['Template', 'TemplateVersion', 'TemplateVersion'])
            ->and($entries->pluck('reason')->all())->toBe(['our own layout', 'tidied', 'went back']);
    });

    it('is never picked for the section on creation, and the company keeps its active template', function () {
        $this->create->handle($this->sl, $this->section, $this->type, 'Ours', 'one', 'why');

        expect($this->repo->choiceFor($this->section->id, $this->type))->toBeNull()
            ->and($this->repo->activeFor($this->company->id, $this->type)->body)->toBe('company standard')
            ->and($this->resolver->forUnit($this->section)->version->body)->toBe('company standard');
    });

    it('cannot be switched or made the company default', function () {
        $own = $this->create->handle($this->sl, $this->section, $this->type, 'Ours', 'one', 'why');

        expect(fn () => app(SwitchTemplate::class)->handle($this->admin, $own, false))->toThrow(InvalidTemplateException::class, 'always available')
            ->and(fn () => app(SetCompanyTemplate::class)->handle($this->admin, $own))->toThrow(InvalidTemplateException::class, "not one of this company's own");
    });

    it('holds its section in place: a section with templates cannot be deleted, as a company with them cannot', function () {
        $empty = app(UnitRepository::class)->create(UnitType::Section, '3rd Section', '3/1/A/1-7', $this->platoon->id);
        $this->repo->createTemplate($this->sibling->id, $this->type, 'Theirs', 'b', $this->admin->id);

        expect(fn () => app(DeleteUnit::class)->handle($this->admin, $this->sibling))->toThrow(UnitHasDependentsException::class, 'template');

        app(DeleteUnit::class)->handle($this->admin, $empty);

        expect(Unit::query()->find($this->sibling->id))->not->toBeNull()
            ->and(Unit::query()->find($empty->id))->toBeNull();
    });
});

describe('which section may use it', function () {
    beforeEach(function () {
        $this->own = $this->create->handle($this->sl, $this->section, $this->type, 'Ours', 'section own', 'why');
        $this->default = $this->repo->regimentDefault($this->type);
    });

    it('offers it to its own section only, beside the regiment default and the company\'s switched-on templates', function () {
        $off = $this->create->handle($this->co, $this->company, $this->type, 'Off', 'off', 'why');
        app(SwitchTemplate::class)->handle($this->co, $off, false);

        expect($this->resolver->optionsFor($this->section)->pluck('id')->all())->toBe([$this->default->id, $this->own->id, $this->companyStandard->id])
            ->and($this->resolver->optionsFor($this->sibling)->pluck('id')->all())->toBe([$this->default->id, $this->companyStandard->id])
            ->and($this->resolver->pickerFor($this->section)['options'][1])->toBe(['id' => $this->own->id, 'name' => 'Ours', 'isRegimentDefault' => false, 'isSection' => true, 'version' => 1]);
    });

    it('lets its leader pick it, and refuses another section\'s leader picking it for theirs', function () {
        app(PickSectionTemplate::class)->handle($this->sl, $this->section->id, $this->own->id);

        expect($this->repo->choiceFor($this->section->id, $this->type)->template_id)->toBe($this->own->id)
            ->and(fn () => app(PickSectionTemplate::class)->handle($this->siblingSl, $this->sibling->id, $this->own->id))
            ->toThrow(InvalidTemplateException::class, 'not available to this section');
    });

    it('never renders for another section, even with a pick written straight to the table', function () {
        $this->repo->choose($this->sibling, $this->own, $this->admin->id);

        expect($this->resolver->forUnit($this->sibling)->version->body)->toBe('company standard')
            ->and($this->resolver->pickerFor($this->sibling)['picked'])->toBeNull();
    });

    it('renders in order: the section\'s pick of its own, else the company default, else the regiment default', function () {
        app(PickSectionTemplate::class)->handle($this->sl, $this->section->id, $this->own->id);
        $picked = $this->resolver->forUnit($this->section);

        expect($picked->template->id)->toBe($this->own->id)
            ->and($picked->source)->toBe(TemplateSource::Picked)
            ->and($picked->version->body)->toBe('section own');

        // Its pick of a company template wins over its own templates, which it did not pick.
        $training = $this->create->handle($this->co, $this->company, $this->type, 'Training', 'company training', 'why');
        app(PickSectionTemplate::class)->handle($this->sl, $this->section->id, $training->id);
        expect($this->resolver->forUnit($this->section)->version->body)->toBe('company training');

        // No pick: the company default, though the section has its own.
        app(PickSectionTemplate::class)->handle($this->sl, $this->section->id, null);
        $company = $this->resolver->forUnit($this->section);
        expect($company->source)->toBe(TemplateSource::Company)->and($company->version->body)->toBe('company standard');

        // The company default switched off: the regiment default.
        app(SwitchTemplate::class)->handle($this->co, $this->companyStandard, false);
        app(SwitchTemplate::class)->handle($this->co, $training, false);
        expect($this->resolver->forUnit($this->section)->source)->toBe(TemplateSource::RegimentDefault);

        // A pick of its own still wins over all of that.
        app(PickSectionTemplate::class)->handle($this->sl, $this->section->id, $this->own->id);
        expect($this->resolver->forUnit($this->section)->version->body)->toBe('section own');
    });

    it('follows an edit of its own on the next render', function () {
        app(PickSectionTemplate::class)->handle($this->sl, $this->section->id, $this->own->id);
        app(AuthorTemplateVersion::class)->handle($this->asl, $this->own, 'section own, edited', 'why');

        expect($this->resolver->forUnit($this->section)->version->body)->toBe('section own, edited');
    });
});

describe('the migrations', function () {
    it('dropped the battalion switch table, and the section migration refuses to roll back over a section template', function () {
        $migration = require database_path('migrations/2026_10_31_100000_add_section_id_to_template_tables.php');
        $this->repo->createTemplate($this->section->id, $this->type, 'Ours', 'one', $this->admin->id);

        expect(Schema::hasTable('company_template_policies'))->toBeFalse()
            ->and(Schema::hasColumns('templates', ['section_id']))->toBeTrue()
            ->and(Schema::hasColumns('template_versions', ['section_id']))->toBeTrue()
            ->and(fn () => $migration->down())->toThrow(RuntimeException::class, 'A section holds templates');
    });

    it('brings the switch table back empty on a rollback, and drops it again', function () {
        $migration = require database_path('migrations/2026_10_31_110000_drop_company_template_policies_table.php');

        $migration->down();
        expect(Schema::hasColumns('company_template_policies', ['company_id', 'company_edits_templates', 'set_by', 'set_by_admin', 'set_as_unit']))->toBeTrue()
            ->and(DB::table('company_template_policies')->count())->toBe(0);

        $migration->up();
        expect(Schema::hasTable('company_template_policies'))->toBeFalse();
    });
});

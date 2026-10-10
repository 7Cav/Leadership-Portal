<?php

use App\Models\ArtifactType;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitRepository;
use App\Rollcall\TemplateResolution;
use App\Rollcall\TemplateResolver;

// Story 3.6 / AD-17, AD-18: a unit renders from its owning company's active template, found by walking up the tree.
// Fixture tree: 7CAV ─ 1-7 ─┬─ A/1-7 ─┬─ 1/A ─┬─ 1/1/A (section)
//                           │         │       └─ 2/1/A (section)
//                           │         └─ 2/A ─── 1/2/A (section)
//                           └─ B/1-7 ─── 1/B ─── 1/1/B (section)

beforeEach(function () {
    $units = app(UnitRepository::class);

    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->alpha = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->bravo = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', $this->battalion->id);
    $this->alphaPlatoon1 = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->alpha->id);
    $this->alphaPlatoon2 = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->alpha->id);
    $this->bravoPlatoon = $units->create(UnitType::Platoon, '1st Platoon', '1/B/1-7', $this->bravo->id);
    $this->alphaSection1 = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->alphaPlatoon1->id);
    $this->alphaSection2 = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->alphaPlatoon1->id);
    $this->alphaSection3 = $units->create(UnitType::Section, '1st Section, 2nd Platoon', '1/2/A/1-7', $this->alphaPlatoon2->id);
    $this->bravoSection = $units->create(UnitType::Section, '1st Section', '1/1/B/1-7', $this->bravoPlatoon->id);

    $this->resolver = app(TemplateResolver::class);
    $this->versions = app(TemplateVersionRepository::class);
    $this->author = User::factory()->create();
    $this->type = ArtifactType::RollcallBbcode;
});

function author(Unit $company, string $body)
{
    return app(TemplateVersionRepository::class)->author($company->id, ArtifactType::RollcallBbcode, $body, User::factory()->create()->id);
}

describe('walking up to the owning company', function () {
    it('resolves a section to its company\'s active version', function () {
        $version = author($this->alpha, 'alpha standard');

        $resolved = $this->resolver->forUnit($this->alphaSection1);

        expect($resolved->isResolved())->toBeTrue()
            ->and($resolved->companyId)->toBe($this->alpha->id)
            ->and($resolved->versionId())->toBe($version->id)
            ->and($resolved->version->body)->toBe('alpha standard');
    });

    it('resolves a platoon the same way, since the walk continues up to the company', function () {
        $version = author($this->alpha, 'alpha standard');

        expect($this->resolver->forUnit($this->alphaPlatoon1)->versionId())->toBe($version->id);
    });

    it('resolves the company node itself to its own version, ending the walk there', function () {
        $version = author($this->alpha, 'alpha standard');

        $resolved = $this->resolver->forUnit($this->alpha);

        expect($resolved->versionId())->toBe($version->id)
            ->and($resolved->companyId)->toBe($this->alpha->id);
    });

    it('gives every unit under one company the same version, whichever platoon or section it is in', function () {
        $version = author($this->alpha, 'alpha standard');

        foreach ([$this->alphaPlatoon1, $this->alphaPlatoon2, $this->alphaSection1, $this->alphaSection2, $this->alphaSection3, $this->alpha] as $unit) {
            expect($this->resolver->forUnit($unit)->versionId())->toBe($version->id, "unit {$unit->name}");
        }
    });

    it('never lets two companies bleed into each other', function () {
        $alpha = author($this->alpha, 'alpha standard');
        $bravo = author($this->bravo, 'bravo standard');

        expect($this->resolver->forUnit($this->alphaSection1)->versionId())->toBe($alpha->id)
            ->and($this->resolver->forUnit($this->bravoSection)->versionId())->toBe($bravo->id)
            ->and($this->resolver->forUnit($this->bravoPlatoon)->version->body)->toBe('bravo standard');
    });

    it('follows the active pointer of the owning company only, whatever other companies hold', function () {
        author($this->alpha, 'alpha one');
        author($this->alpha, 'alpha two');
        author($this->bravo, 'bravo one');

        expect($this->resolver->forUnit($this->alphaSection1)->version->body)->toBe('alpha two')
            ->and($this->resolver->forUnit($this->alphaSection1)->version->version)->toBe(2);
    });

    it('is deterministic: the same unit resolves to the same version every time', function () {
        author($this->alpha, 'alpha standard');

        $ids = collect(range(1, 5))->map(fn () => $this->resolver->forUnit($this->alphaSection2)->versionId())->unique();

        expect($ids)->toHaveCount(1);
    });

    it('follows a unit that moves to another company on the next resolve', function () {
        $alpha = author($this->alpha, 'alpha standard');
        $bravo = author($this->bravo, 'bravo standard');
        expect($this->resolver->forUnit($this->alphaPlatoon2)->versionId())->toBe($alpha->id);

        app(UnitRepository::class)->move($this->alphaPlatoon2, $this->bravo->id);

        expect($this->resolver->forUnit(Unit::query()->find($this->alphaSection3->id))->versionId())->toBe($bravo->id);
    });
});

describe('when nothing resolves', function () {
    it('says the company has no active template, rather than a blank body or an error', function () {
        $resolved = $this->resolver->forUnit($this->alphaSection1);

        expect($resolved->isResolved())->toBeFalse()
            ->and($resolved->state)->toBe(TemplateResolution::NoActiveTemplate)
            ->and($resolved->companyId)->toBe($this->alpha->id)
            ->and($resolved->version)->toBeNull()
            ->and($resolved->versionId())->toBeNull();
    });

    it('says no active template for one company while another has one', function () {
        author($this->bravo, 'bravo standard');

        expect($this->resolver->forUnit($this->alphaSection1)->state)->toBe(TemplateResolution::NoActiveTemplate)
            ->and($this->resolver->forUnit($this->bravoSection)->state)->toBe(TemplateResolution::Resolved);
    });

    it('says a unit above every company has no owning company', function () {
        author($this->alpha, 'alpha standard');

        foreach ([$this->regiment, $this->battalion] as $unit) {
            $resolved = $this->resolver->forUnit($unit);

            expect($resolved->state)->toBe(TemplateResolution::NoOwningCompany)
                ->and($resolved->companyId)->toBeNull()
                ->and($resolved->version)->toBeNull();
        }
    });

    it('treats a unit with no path as having no owning company', function () {
        $orphan = Unit::factory()->create(['type' => UnitType::Section]);
        $orphan->forceFill(['path' => ''])->save();

        expect($this->resolver->forUnit($orphan)->state)->toBe(TemplateResolution::NoOwningCompany);
    });
});

it('hands a render the version id and a prop shape that names the state', function () {
    $version = author($this->alpha, "[B]body[/B]\nline two");

    expect($this->resolver->forUnit($this->alphaSection1)->toArray())->toBe([
        'state' => 'resolved',
        'companyId' => $this->alpha->id,
        'versionId' => $version->id,
        'version' => 1,
        'body' => "[B]body[/B]\nline two",
    ])->and($this->resolver->forUnit($this->bravoSection)->toArray())->toBe([
        'state' => 'no_active_template',
        'companyId' => $this->bravo->id,
        'versionId' => null,
        'version' => null,
        'body' => null,
    ])->and($this->resolver->forUnit($this->battalion)->toArray())->toBe([
        'state' => 'no_owning_company',
        'companyId' => null,
        'versionId' => null,
        'version' => null,
        'body' => null,
    ]);
});

it('reads a fresh answer each time, so an activation shows on the next render and nothing is cached', function () {
    $first = author($this->alpha, 'first');
    expect($this->resolver->forUnit($this->alphaSection1)->versionId())->toBe($first->id);

    $second = author($this->alpha, 'second');
    expect($this->resolver->forUnit($this->alphaSection1)->versionId())->toBe($second->id);

    $this->versions->activate($first, $this->author->id);
    expect($this->resolver->forUnit($this->alphaSection1)->versionId())->toBe($first->id);
});

it('resolves in a constant number of queries however deep the unit sits', function () {
    author($this->alpha, 'alpha standard');

    $count = function (Unit $unit) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->resolver->forUnit($unit);

        return count(DB::getQueryLog());
    };

    expect($count($this->alphaSection1))->toBe($count($this->alpha))->toBe(2);
});

it('is read-only: resolving writes nothing', function () {
    author($this->alpha, 'alpha standard');
    $before = [DB::table('template_versions')->count(), DB::table('active_template_versions')->count(), DB::table('audit_log')->count()];

    $this->resolver->forUnit($this->alphaSection1);

    expect([DB::table('template_versions')->count(), DB::table('active_template_versions')->count(), DB::table('audit_log')->count()])->toBe($before);
});

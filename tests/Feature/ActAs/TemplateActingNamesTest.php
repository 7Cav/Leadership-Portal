<?php

use App\Models\ArtifactType;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitTemplateChoice;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitRepository;
use Inertia\Testing\AssertableInertia as Assert;

// #379 for the rollcall templates (#375): a template (a company's or a section's own), a version and a unit's choice
// keep naming the person an Admin acted as, with the Admin beside them ("Hale.S for Pike.S"), and name the Admin with
// the unit whose billet they held ("Hale.S acting as Company Commander A/1-7"). Done in one's own name, both stay null.

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', null);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);

    $this->bn = tanCommander($this->battalion, 801, 'Rook.T');
    $this->co = tanCommander($this->company, 802, 'Pike.S');
    $this->sl = tanCommander($this->section, 803, 'Mercer.J');

    $this->admin = User::factory()->admin()->create(['username' => 'Hale.S']);
    $this->repo = app(TemplateVersionRepository::class);
    $this->type = ArtifactType::RollcallBbcode;
});

function tanCommander(Unit $unit, int $positionId, string $username): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $positionId]);
    $trooper = Trooper::factory()->create(['cav_position_id' => $positionId, 'username' => $username, 'status' => TrooperStatus::Combat]);

    return User::factory()->create(['cav_id' => $trooper->cav_id, 'forum_user_id' => $trooper->forum_user_id, 'username' => $username]);
}

/** Start acting: as a person (`$target` a user) or as a billet (`$target` a unit). */
function tanActAs(object $test, User|Unit $target): void
{
    $kind = $target instanceof User ? 'user' : 'unit';
    $test->actingAs($test->admin)->post('/act-as', ['kind' => $kind, 'id' => $target->id])->assertSessionHasNoErrors();
}

/** The template's history row for version `$number`, as the Templates page shows it to the Admin in their own name. */
function tanHistoryAuthor(object $test, Template $template, int $number): ?string
{
    $test->actingAs($test->admin)->delete('/act-as');
    $by = null;

    $test->actingAs($test->admin)->get("/settings/templates?company={$template->company_id}&template={$template->id}")
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$by, $number) {
            $by = collect($page->toArray()['props']['history'])->firstWhere('version', $number)['createdBy'];
        });

    return $by;
}

/** The (by, by admin, as unit) of a row. */
function tanWho(object $row, string $verb): array
{
    return [$row->{"{$verb}_by"}, $row->{"{$verb}_by_admin"}, $row->{"{$verb}_as_unit"}];
}

describe('creating a template', function () {
    it('names the Admin acting as the CO beside the CO on the template, its version and the company\'s choice', function () {
        tanActAs($this, $this->co);

        $this->actingAs($this->admin)->post('/settings/templates/new', ['company_id' => $this->company->id, 'name' => 'Training', 'body' => '[B]{section}[/B]', 'reason' => 'New template'])
            ->assertSessionHasNoErrors();

        $template = Template::query()->where('company_id', $this->company->id)->sole();
        $expected = [$this->co->id, $this->admin->id, null];

        expect(tanWho($template, 'created'))->toBe($expected)
            ->and(tanWho($template->activeVersion, 'created'))->toBe($expected)
            ->and(tanWho(UnitTemplateChoice::query()->where('unit_id', $this->company->id)->sole(), 'chosen'))->toBe($expected)
            ->and(tanHistoryAuthor($this, $template, 1))->toBe('Hale.S for Pike.S');
    });

    it('names the billet an Admin held', function () {
        tanActAs($this, $this->company);

        $this->actingAs($this->admin)->post('/settings/templates/new', ['company_id' => $this->company->id, 'name' => 'Training', 'body' => '[B]{section}[/B]', 'reason' => 'New template'])
            ->assertSessionHasNoErrors();

        $template = Template::query()->where('company_id', $this->company->id)->sole();
        $expected = [$this->admin->id, null, $this->company->id];

        expect(tanWho($template, 'created'))->toBe($expected)
            ->and(tanWho($template->activeVersion, 'created'))->toBe($expected)
            ->and(tanWho(UnitTemplateChoice::query()->where('unit_id', $this->company->id)->sole(), 'chosen'))->toBe($expected)
            ->and(tanHistoryAuthor($this, $template, 1))->toBe('Hale.S acting as Company Commander A/1-7');
    });

    it('leaves both empty for a CO creating it in their own name', function () {
        $this->actingAs($this->co)->post('/settings/templates/new', ['company_id' => $this->company->id, 'name' => 'Training', 'body' => '[B]{section}[/B]', 'reason' => 'New template'])
            ->assertSessionHasNoErrors();

        $template = Template::query()->where('company_id', $this->company->id)->sole();

        expect(tanWho($template, 'created'))->toBe([$this->co->id, null, null])
            ->and(tanWho($template->activeVersion, 'created'))->toBe([$this->co->id, null, null])
            ->and(tanHistoryAuthor($this, $template, 1))->toBe('Pike.S');
    });
});

describe('editing a template', function () {
    beforeEach(function () {
        $this->template = $this->repo->createTemplate($this->company->id, $this->type, 'Training', 'first', $this->co->id);
    });

    it('names the Admin acting as the CO on the new version', function () {
        tanActAs($this, $this->co);

        $this->actingAs($this->admin)->post('/settings/templates', ['template_id' => $this->template->id, 'body' => 'second', 'reason' => 'Fix'])
            ->assertSessionHasNoErrors();

        $version = TemplateVersion::query()->where('template_id', $this->template->id)->where('version', 2)->sole();

        expect(tanWho($version, 'created'))->toBe([$this->co->id, $this->admin->id, null])
            ->and(tanHistoryAuthor($this, $this->template, 2))->toBe('Hale.S for Pike.S')
            ->and(tanHistoryAuthor($this, $this->template, 1))->toBe('Pike.S');
    });

    it('names the billet an Admin held on the new version', function () {
        tanActAs($this, $this->company);

        $this->actingAs($this->admin)->post('/settings/templates', ['template_id' => $this->template->id, 'body' => 'second', 'reason' => 'Fix'])
            ->assertSessionHasNoErrors();

        $version = TemplateVersion::query()->where('template_id', $this->template->id)->where('version', 2)->sole();

        expect(tanWho($version, 'created'))->toBe([$this->admin->id, null, $this->company->id])
            ->and(tanHistoryAuthor($this, $this->template, 2))->toBe('Hale.S acting as Company Commander A/1-7');
    });

    it('leaves both empty for a CO editing in their own name', function () {
        $this->actingAs($this->co)->post('/settings/templates', ['template_id' => $this->template->id, 'body' => 'second', 'reason' => 'Fix'])
            ->assertSessionHasNoErrors();

        $version = TemplateVersion::query()->where('template_id', $this->template->id)->where('version', 2)->sole();

        expect(tanWho($version, 'created'))->toBe([$this->co->id, null, null]);
    });
});

describe('activating a version', function () {
    it('writes no who column of its own: the versions keep their authors', function () {
        $template = $this->repo->createTemplate($this->company->id, $this->type, 'Training', 'first', $this->co->id);
        $this->repo->authorVersion($template, 'second', $this->co->id);
        $first = TemplateVersion::query()->where('template_id', $template->id)->where('version', 1)->sole();
        tanActAs($this, $this->co);

        $this->actingAs($this->admin)->post("/settings/templates/{$first->id}/activate", ['reason' => 'Roll back'])
            ->assertSessionHasNoErrors();

        expect($template->fresh()->active_version_id)->toBe($first->id)
            ->and(TemplateVersion::query()->whereNotNull('created_by_admin')->exists())->toBeFalse()
            ->and(tanHistoryAuthor($this, $template, 1))->toBe('Pike.S');
    });
});

describe('setting the company\'s active template', function () {
    beforeEach(function () {
        $this->repo->createTemplate($this->company->id, $this->type, 'Standard', 'standard', $this->co->id);
        $this->second = $this->repo->createTemplate($this->company->id, $this->type, 'Training', 'training', $this->co->id);
        $this->choice = fn () => UnitTemplateChoice::query()->where('unit_id', $this->company->id)->sole();
    });

    it('names the Admin acting as the CO', function () {
        tanActAs($this, $this->co);

        $this->actingAs($this->admin)->post("/settings/templates/{$this->second->id}/company-active")->assertSessionHasNoErrors();

        expect(tanWho(($this->choice)(), 'chosen'))->toBe([$this->co->id, $this->admin->id, null]);
    });

    it('names the billet an Admin held', function () {
        tanActAs($this, $this->company);

        $this->actingAs($this->admin)->post("/settings/templates/{$this->second->id}/company-active")->assertSessionHasNoErrors();

        expect(tanWho(($this->choice)(), 'chosen'))->toBe([$this->admin->id, null, $this->company->id]);
    });

    it('leaves both empty for a CO setting it in their own name', function () {
        $this->actingAs($this->co)->post("/settings/templates/{$this->second->id}/company-active")->assertSessionHasNoErrors();

        expect(tanWho(($this->choice)(), 'chosen'))->toBe([$this->co->id, null, null]);
    });
});

describe('picking a section\'s template', function () {
    beforeEach(function () {
        $this->template = $this->repo->createTemplate($this->company->id, $this->type, 'Training', 'training', $this->co->id);
        $this->choice = fn () => UnitTemplateChoice::query()->where('unit_id', $this->section->id)->sole();
    });

    it('names the Admin acting as the section leader', function () {
        tanActAs($this, $this->sl);

        $this->actingAs($this->admin)->post("/rollcall/{$this->section->id}/template", ['template_id' => $this->template->id])->assertSessionHasNoErrors();

        expect(tanWho(($this->choice)(), 'chosen'))->toBe([$this->sl->id, $this->admin->id, null]);
    });

    it('names the billet an Admin held', function () {
        tanActAs($this, $this->section);

        $this->actingAs($this->admin)->post("/rollcall/{$this->section->id}/template", ['template_id' => $this->template->id])->assertSessionHasNoErrors();

        expect(tanWho(($this->choice)(), 'chosen'))->toBe([$this->admin->id, null, $this->section->id]);
    });

    it('leaves both empty for a leader picking in their own name', function () {
        $this->actingAs($this->sl)->post("/rollcall/{$this->section->id}/template", ['template_id' => $this->template->id])->assertSessionHasNoErrors();

        expect(tanWho(($this->choice)(), 'chosen'))->toBe([$this->sl->id, null, null]);
    });
});

describe('a section\'s own template', function () {
    beforeEach(function () {
        $this->own = fn () => Template::query()->where('section_id', $this->section->id)->sole();
        $this->create = fn () => $this->post('/settings/templates/new', ['section_id' => $this->section->id, 'name' => 'Ours', 'body' => 'one', 'reason' => 'Our own'])
            ->assertSessionHasNoErrors();
    });

    it('names the Admin acting as the section leader on the template and its versions, and the history says so', function () {
        tanActAs($this, $this->sl);
        $this->actingAs($this->admin);
        ($this->create)();
        $this->actingAs($this->admin)->post('/settings/templates', ['template_id' => ($this->own)()->id, 'body' => 'two', 'reason' => 'Edit'])->assertSessionHasNoErrors();

        $own = ($this->own)();
        $versions = TemplateVersion::query()->where('template_id', $own->id)->orderBy('version')->get();

        expect(tanWho($own, 'created'))->toBe([$this->sl->id, $this->admin->id, null])
            ->and($versions->map(fn (TemplateVersion $version) => tanWho($version, 'created'))->all())
            ->toBe([[$this->sl->id, $this->admin->id, null], [$this->sl->id, $this->admin->id, null]])
            ->and(UnitTemplateChoice::query()->where('unit_id', $this->section->id)->exists())->toBeFalse();

        $this->actingAs($this->admin)->delete('/act-as');
        $this->get("/settings/templates?section={$this->section->id}&template={$own->id}")->assertInertia(fn (Assert $page) => $page
            ->where('history.0.createdBy', 'Hale.S for Mercer.J'));
    });

    it('names the billet an Admin held', function () {
        tanActAs($this, $this->section);
        $this->actingAs($this->admin);
        ($this->create)();

        expect(tanWho(($this->own)(), 'created'))->toBe([$this->admin->id, null, $this->section->id])
            ->and(tanWho(($this->own)()->activeVersion, 'created'))->toBe([$this->admin->id, null, $this->section->id]);
    });

    it('leaves both empty for a section leader in their own name', function () {
        $this->actingAs($this->sl);
        ($this->create)();

        expect(tanWho(($this->own)(), 'created'))->toBe([$this->sl->id, null, null]);
    });
});

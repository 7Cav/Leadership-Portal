<?php

use App\Commands\Templates\AuthorTemplateVersion;
use App\Models\ArtifactType;
use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\FireteamOrder;
use App\Models\PortalRoleGrant;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\TemplateVersion;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\FireteamRepository;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitRepository;
use App\Rollcall\TemplateSyntax;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

// Story 3.5, #375: the template screen. An Admin views every company and anyone else only the companies in their
// scope; a company's command writes its own company's templates and only an Admin the regiment default, checked on
// the server (AD-8, AD-16, AD-17).

beforeEach(function () {
    $this->company = Unit::factory()->create(['type' => UnitType::Company, 'name' => 'Echo Company', 'callsign' => 'E/2-7']);
    $this->admin = User::factory()->admin()->create();
    $this->repo = app(TemplateVersionRepository::class);
});

function previewTrooper(Unit $section, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

function authorTemplate($test, string $body, ?Unit $company = null): TemplateVersion
{
    return app(TemplateVersionRepository::class)->author(($company ?? $test->company)->id, ArtifactType::RollcallBbcode, $body, $test->admin->id);
}

describe('viewing', function () {
    it('requires authentication', function () {
        $this->get('/settings/templates')->assertRedirect(route('login'));
    });

    it('shows a leader in the company the active template and its history, read-only', function () {
        authorTemplate($this, 'first body');
        authorTemplate($this, 'second body');
        $leader = User::factory()->serving()->create();
        PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $this->company->id]);

        $this->actingAs($leader)->get('/settings/templates')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Settings/Templates')
            ->where('isAdmin', false)
            ->where('company', $this->company->id)
            ->where('active.body', 'second body')
            ->where('active.version', 2)
            ->where('artifactType', ['value' => 'rollcall_bbcode', 'label' => 'Rollcall BBCode'])
            ->has('history', 2)
            ->where('history.0.isActive', true)
            ->where('history.1.isActive', false));
    });

    it('sends a starter template', function () {
        $this->actingAs($this->admin)->get('/settings/templates')->assertInertia(fn ($page) => $page
            ->where('starter', TemplateSyntax::STARTER));
    });

    it('lists the history newest first, with who saved each version', function () {
        authorTemplate($this, 'first body');
        authorTemplate($this, 'second body');

        $this->actingAs($this->admin)->get('/settings/templates')->assertInertia(fn ($page) => $page
            ->where('history.0.version', 2)
            ->where('history.1.version', 1)
            ->where('history.0.createdBy', $this->admin->username)
            ->where('isAdmin', true));
    });

    it('offers only company units, and opens the one asked for', function () {
        $other = Unit::factory()->create(['type' => UnitType::Company, 'name' => 'Foxtrot Company']);
        Unit::factory()->create(['type' => UnitType::Platoon]);
        authorTemplate($this, 'echo body');
        authorTemplate($this, 'foxtrot body', $other);

        $this->actingAs($this->admin)->get('/settings/templates?company='.$other->id)->assertInertia(fn ($page) => $page
            ->has('companies', 2)
            ->where('company', $other->id)
            ->where('active.body', 'foxtrot body')
            ->has('history', 1));
    });

    it('falls back to the first company for a company id that is not one', function () {
        $section = Unit::factory()->create(['type' => UnitType::Section]);

        $this->actingAs($this->admin)->get('/settings/templates?company='.$section->id)->assertInertia(fn ($page) => $page->where('company', $this->company->id));
    });

    it('shows a signed-in user with no scope no company at all', function () {
        authorTemplate($this, 'echo body');

        $this->actingAs(User::factory()->serving()->create())->get('/settings/templates')->assertOk()->assertInertia(fn ($page) => $page
            ->where('companies', [])
            ->where('company', null)
            ->has('templates', 1)
            ->where('templates.0.isRegimentDefault', true)
            ->where('canManage', false)
            ->where('canManageCompany', false));
    });

    it('opens a company with no template of its own on the regiment default, which it uses', function () {
        $default = $this->repo->regimentDefault(ArtifactType::RollcallBbcode);

        $this->actingAs($this->admin)->get('/settings/templates')->assertInertia(fn ($page) => $page
            ->where('templates', [['id' => $default->id, 'name' => 'Regiment default', 'isRegimentDefault' => true, 'enabled' => true, 'isCompanyActive' => false, 'version' => 1]])
            ->where('template', $default->id)
            ->where('active.body', TemplateSyntax::STARTER)
            ->has('history', 1));
    });

    it('renders with no companies at all', function () {
        $this->company->delete();

        $this->actingAs($this->admin)->get('/settings/templates')->assertOk()->assertInertia(fn ($page) => $page
            ->where('companies', [])
            ->where('company', null)
            ->where('canManageCompany', false));
    });

    it('follows a view-as preview for the controls it shows, and the real flag for writes', function () {
        $leader = Unit::factory()->create(['type' => UnitType::Section]);
        $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $leader->id])->assertSessionHasNoErrors();

        $this->get('/settings/templates')->assertInertia(fn ($page) => $page->where('isAdmin', false));
    });
});

describe('saving a new version', function () {
    beforeEach(function () {
        $this->first = authorTemplate($this, 'first body');
        $this->template = $this->first->template;
    });

    it('lets an Admin save an edit, which becomes the next version and the active one', function () {
        $this->actingAs($this->admin)->post('/settings/templates', [
            'template_id' => $this->template->id,
            'body' => '[CENTER]{{unit}}[/CENTER]',
            'reason' => 'First authoring',
        ])->assertRedirect(route('settings.templates', ['company' => $this->company->id, 'template' => $this->template->id]));

        $active = $this->repo->activeFor($this->company->id, ArtifactType::RollcallBbcode);

        expect($active->version)->toBe(2)
            ->and($active->body)->toBe('[CENTER]{{unit}}[/CENTER]')
            ->and(AuditLogEntry::query()->sole()->reason)->toBe('First authoring');
    });

    it('refuses anyone who does not command the company on the server, whatever the page showed, and writes nothing', function () {
        $this->actingAs(User::factory()->serving()->create())->post('/settings/templates', [
            'template_id' => $this->template->id,
            'body' => 'body',
            'reason' => 'why',
        ])->assertForbidden();

        expect(TemplateVersion::query()->where('template_id', $this->template->id)->count())->toBe(1)
            ->and(AuditLogEntry::query()->count())->toBe(0);
    });

    it('refuses a signed-out request', function () {
        $this->post('/settings/templates', ['template_id' => $this->template->id, 'body' => 'b', 'reason' => 'r'])->assertRedirect(route('login'));
    });

    it('refuses even a view-as preview: the previewed state is read-only', function () {
        $leader = Unit::factory()->create(['type' => UnitType::Section]);
        $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $leader->id])->assertSessionHasNoErrors();

        $this->post('/settings/templates', ['template_id' => $this->template->id, 'body' => 'b', 'reason' => 'r']);

        expect(TemplateVersion::query()->where('template_id', $this->template->id)->count())->toBe(1);
    });

    it('validates the template, body and reason', function (array $payload, string $field) {
        $this->actingAs($this->admin)->post('/settings/templates', $payload + ['template_id' => $this->template->id, 'body' => 'body', 'reason' => 'why'])
            ->assertSessionHasErrors($field);

        expect(TemplateVersion::query()->where('template_id', $this->template->id)->count())->toBe(1);
    })->with([
        'no body' => [['body' => ''], 'body'],
        'no reason' => [['reason' => ''], 'reason'],
        'reason too long' => [['reason' => str_repeat('r', 1001)], 'reason'],
        'unknown template' => [['template_id' => 999999], 'template_id'],
        'body too long' => [['body' => str_repeat('b', AuthorTemplateVersion::MAX_BODY_LENGTH + 1)], 'body'],
    ]);

    it('keeps the earlier version when an Admin saves a second edit', function () {
        $first = $this->first;

        $this->actingAs($this->admin)->post('/settings/templates', ['template_id' => $this->template->id, 'body' => 'second body', 'reason' => 'tweak']);

        expect(TemplateVersion::query()->where('template_id', $this->template->id)->count())->toBe(2)
            ->and($this->repo->find($first->id)->body)->toBe('first body')
            ->and($this->repo->activeFor($this->company->id, ArtifactType::RollcallBbcode)->body)->toBe('second body');
    });
});

describe('making an older version active', function () {
    it('lets an Admin roll back, with a reason, and writes no new version', function () {
        $first = authorTemplate($this, 'first body');
        authorTemplate($this, 'second body');

        $this->actingAs($this->admin)->post("/settings/templates/{$first->id}/activate", ['reason' => 'The edit broke the table'])
            ->assertRedirect(route('settings.templates', ['company' => $this->company->id, 'template' => $first->template_id]));

        expect($this->repo->activeFor($this->company->id, ArtifactType::RollcallBbcode)->id)->toBe($first->id)
            ->and(TemplateVersion::query()->where('template_id', $first->template_id)->count())->toBe(2);
    });

    it('refuses anyone who does not command the company on the server and moves nothing', function () {
        $first = authorTemplate($this, 'first body');
        $second = authorTemplate($this, 'second body');

        $this->actingAs(User::factory()->serving()->create())->post("/settings/templates/{$first->id}/activate", ['reason' => 'why'])->assertForbidden();

        expect($this->repo->activeFor($this->company->id, ArtifactType::RollcallBbcode)->id)->toBe($second->id);
    });

    it('requires a reason', function () {
        $first = authorTemplate($this, 'first body');
        authorTemplate($this, 'second body');

        $this->actingAs($this->admin)->post("/settings/templates/{$first->id}/activate", ['reason' => ''])->assertSessionHasErrors('reason');
    });

    it('answers a version that does not exist with 404', function () {
        $this->actingAs($this->admin)->post('/settings/templates/999999/activate', ['reason' => 'why'])->assertNotFound();
    });
});

describe('previewing with a real section', function () {
    beforeEach(function () {
        // Thursday 08Oct26, inside the Cav week that opened Saturday 03Oct26.
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));

        $this->alpha = Unit::factory()->create(['type' => UnitType::Company, 'name' => 'Alpha Company', 'callsign' => 'A/1-7', 'path' => '800/']);
        $this->platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'parent_id' => $this->alpha->id, 'name' => '1st Platoon', 'callsign' => '1/A/1-7', 'path' => '800/801/']);
        $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'name' => '2nd Section', 'callsign' => '2/1/A/1-7', 'path' => '800/801/802/']);
        $this->sibling = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'name' => '3rd Section', 'callsign' => '3/1/A/1-7', 'path' => '800/801/803/']);

        $this->bravo = Unit::factory()->create(['type' => UnitType::Company, 'name' => 'Bravo Company', 'callsign' => 'B/1-7', 'path' => '810/']);
        $this->elsewhere = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->bravo->id, 'name' => '1st Section', 'callsign' => '1/1/B/1-7', 'path' => '810/811/']);

        $this->leader = User::factory()->serving()->create();
        PortalRoleGrant::factory()->create(['user_id' => $this->leader->id, 'unit_id' => $this->section->id]);
    });

    it('offers an Admin every section under the company', function () {
        $this->actingAs($this->admin)->get('/settings/templates?company='.$this->alpha->id)->assertInertia(fn ($page) => $page
            ->where('previewSections', [
                ['id' => $this->section->id, 'designation' => '2/1/A/1-7', 'name' => '2nd Section', 'platoon' => '1/A/1-7', 'label' => null],
                ['id' => $this->sibling->id, 'designation' => '3/1/A/1-7', 'name' => '3rd Section', 'platoon' => '1/A/1-7', 'label' => null],
            ]));
    });

    it('offers anyone else only the sections under the company in their scope', function () {
        $this->actingAs($this->leader)->get('/settings/templates?company='.$this->alpha->id)->assertInertia(fn ($page) => $page
            ->has('previewSections', 1)
            ->where('previewSections.0.id', $this->section->id));

        $this->actingAs($this->leader)->get('/settings/templates?company='.$this->bravo->id)->assertForbidden();
    });

    it('answers the section, this week and the same trooper rows the rollcall page sends', function () {
        $signed = previewTrooper($this->section, ['rank' => 'Sergeant', 'rank_short' => 'SGT', 'username' => 'Vasquez.R', 'rank_image_url' => 'https://7cav.us/data/roster_ranks/0/12.jpg']);
        previewTrooper($this->section, ['rank_short' => 'PVT', 'username' => 'Lowe.K']);
        previewTrooper($this->sibling, ['username' => 'Other.S']);
        $practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC')]);
        Attendance::factory()->create([
            'trooper_id' => $signed->id, 'section_practice_id' => $practice->id,
            'status' => AttendanceStatus::Present, 'source' => AttendanceSource::Manual, 'rollcall' => RollcallSignature::WillAttend,
        ]);

        $rollcall = null;
        $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")->assertInertia(function ($page) use (&$rollcall) {
            $rollcall = $page->toArray()['props'];
        });
        // The card's attendance strip is the page's own; the post, and so the preview, does not read it.
        $rollcall['troopers'] = array_map(fn (array $row) => array_diff_key($row, ['history' => true]), $rollcall['troopers']);

        $this->actingAs($this->leader)->getJson("/settings/templates/preview/{$this->section->id}?company={$this->alpha->id}")
            ->assertOk()
            ->assertExactJson([
                'section' => $rollcall['section'],
                'week' => $rollcall['week'],
                'troopers' => $rollcall['troopers'],
                // How the post shows fireteams, as the rollcall page has it; the preview lets nobody change it.
                'fireteams' => [...$rollcall['fireteams'], 'can' => ['edit' => false, 'editPolicy' => false]],
            ])
            ->assertJsonPath('section.designation', '2/1/A/1-7')
            ->assertJsonPath('week.date', '2026-10-03')
            ->assertJsonCount(2, 'troopers')
            ->assertJsonPath('troopers.1.name', 'Vasquez.R')
            ->assertJsonPath('troopers.1.status', 'present')
            ->assertJsonPath('troopers.1.rollcall', 'will_attend')
            ->assertJsonPath('troopers.1.rankIcon', 'https://7cav.us/data/roster_ranks/0/12.jpg');
    });

    it('sends the section\'s fireteams and their display, so the preview orders and labels the post as the rollcall does', function () {
        $ftl = previewTrooper($this->section, ['username' => 'Adams.P']);
        $member = previewTrooper($this->section, ['username' => 'Baker.Q']);
        $fireteams = app(FireteamRepository::class);
        $fireteams->makeLeader($this->section->id, $ftl->id);
        $fireteams->makeMember($this->section->id, $member->id, $ftl->id);
        $fireteams->updateSetting($this->section->id, ['show_fireteams' => true, 'fireteam_order' => FireteamOrder::ByFireteam]);

        $rollcall = null;
        $this->actingAs($this->admin)->get("/rollcall/{$this->section->id}")->assertInertia(function ($page) use (&$rollcall) {
            $rollcall = $page->toArray()['props'];
        });

        $response = $this->actingAs($this->admin)->getJson("/settings/templates/preview/{$this->section->id}?company={$this->alpha->id}")
            ->assertOk()
            ->assertJsonPath('fireteams.show', true)
            ->assertJsonPath('fireteams.order', FireteamOrder::ByFireteam->value)
            // The Admin could change both on the rollcall page; the preview is read-only either way.
            ->assertJsonPath('fireteams.can', ['edit' => false, 'editPolicy' => false]);

        expect(array_diff_key($response->json('fireteams'), ['can' => true]))->toBe(array_diff_key($rollcall['fireteams'], ['can' => true]))
            ->and($rollcall['fireteams']['can']['edit'])->toBeTrue()
            ->and(collect($response->json('troopers'))->firstWhere('id', $member->id)['fireteamLeaderId'])->toBe($ftl->id);
    });

    it('refuses a section outside the viewer\'s scope with 403, as the rollcall page does', function () {
        $this->actingAs($this->leader)->getJson("/settings/templates/preview/{$this->sibling->id}?company={$this->alpha->id}")->assertForbidden();
    });

    it('answers 404 for a section under another company, a unit that is not a section, or a company that is not one', function (string $unit, string $company) {
        $ids = ['section' => $this->section->id, 'elsewhere' => $this->elsewhere->id, 'platoon' => $this->platoon->id, 'alpha' => $this->alpha->id, 'bravo' => $this->bravo->id];

        $this->actingAs($this->admin)->getJson("/settings/templates/preview/{$ids[$unit]}?company={$ids[$company]}")->assertNotFound();
    })->with([
        'section of another company' => ['elsewhere', 'alpha'],
        'a platoon' => ['platoon', 'alpha'],
        'a company id that is a section' => ['section', 'section'],
    ]);

    it('requires the company being edited', function () {
        $this->actingAs($this->admin)->getJson("/settings/templates/preview/{$this->section->id}")->assertUnprocessable();
    });

    it('requires authentication', function () {
        $this->get("/settings/templates/preview/{$this->section->id}?company={$this->alpha->id}")->assertRedirect(route('login'));
    });
});

describe('which companies a viewer may read', function () {
    beforeEach(function () {
        $this->alpha = Unit::factory()->create(['type' => UnitType::Company, 'name' => 'Alpha Company', 'callsign' => 'A/1-7', 'path' => '900/']);
        $this->platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'parent_id' => $this->alpha->id, 'name' => '1st Platoon', 'callsign' => '1/A/1-7', 'path' => '900/901/']);
        $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'name' => '2nd Section', 'callsign' => '2/1/A/1-7', 'path' => '900/901/902/']);
        $this->bravo = Unit::factory()->create(['type' => UnitType::Company, 'name' => 'Bravo Company', 'callsign' => 'B/1-7', 'path' => '910/']);
        $this->company->forceFill(['path' => '920/'])->save();

        authorTemplate($this, 'alpha body', $this->alpha);
        authorTemplate($this, 'bravo body', $this->bravo);

        $this->leader = User::factory()->serving()->create();
        PortalRoleGrant::factory()->create(['user_id' => $this->leader->id, 'unit_id' => $this->section->id]);
    });

    it('lists only the companies a leader\'s scope reaches into, and opens their own by default', function () {
        $this->actingAs($this->leader)->get('/settings/templates')->assertOk()->assertInertia(fn ($page) => $page
            ->where('companies', [['id' => $this->alpha->id, 'name' => 'Alpha Company', 'callsign' => 'A/1-7']])
            ->where('company', $this->alpha->id)
            ->where('active.body', 'alpha body'));
    });

    it('refuses a leader another company\'s template and history, or an id that is no company, with 403', function (string $unit) {
        $this->actingAs($this->leader)->get('/settings/templates?company='.$this->{$unit}->id)->assertForbidden();
    })->with(['bravo', 'company', 'platoon']);

    it('refuses an unknown company id with the same 403', function () {
        $this->actingAs($this->leader)->get('/settings/templates?company=99999')->assertForbidden();
    });

    it('shows an Admin every company', function () {
        $this->actingAs($this->admin)->get('/settings/templates?company='.$this->bravo->id)->assertOk()->assertInertia(fn ($page) => $page
            ->has('companies', 3)
            ->where('company', $this->bravo->id)
            ->where('active.body', 'bravo body'));
    });

    it('narrows an Admin previewing a leader to that leader\'s companies', function () {
        $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section->id])->assertSessionHasNoErrors();

        $this->get('/settings/templates')->assertInertia(fn ($page) => $page
            ->has('companies', 1)
            ->where('company', $this->alpha->id));
        $this->get('/settings/templates?company='.$this->bravo->id)->assertForbidden();
    });
});

function templatesLeaderOf(Unit $unit, int $cavId): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $cavId + 10000]);
    Trooper::factory()->create(['cav_id' => $cavId, 'cav_position_id' => $cavId + 10000]);

    return User::factory()->serving()->create(['cav_id' => $cavId]);
}

describe('which company and section it opens on', function () {
    beforeEach(function () {
        // Created out of designation order: Bravo before Alpha, and in Alpha the 2nd Platoon and a 2nd Section first.
        $units = app(UnitRepository::class);
        $this->company->delete();
        $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', null);
        $this->bravo = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', $battalion->id);
        $this->bravoPlatoon = $units->create(UnitType::Platoon, '1st Platoon', '1/B/1-7', $this->bravo->id);
        $this->bravoSection = $units->create(UnitType::Section, '1st Section', '1/1/B/1-7', $this->bravoPlatoon->id);
        $this->alpha = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
        $secondPlatoon = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->alpha->id);
        $this->alphaLate = $units->create(UnitType::Section, '1st Section', '1/2/A/1-7', $secondPlatoon->id);
        $firstPlatoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->alpha->id);
        $this->alphaSecond = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $firstPlatoon->id);
        $this->alphaFirst = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $firstPlatoon->id);
    });

    it('lists the companies in designation order and opens an Admin with no billet on the first', function () {
        $this->actingAs($this->admin)->get('/settings/templates')->assertInertia(fn ($page) => $page
            ->where('companies.0.callsign', 'A/1-7')
            ->where('companies.1.callsign', 'B/1-7')
            ->where('company', $this->alpha->id));
    });

    it('opens on the company the viewer\'s billet sits under, not the first one', function (string $unit) {
        $leader = templatesLeaderOf($unit === 'company' ? $this->bravo : ($unit === 'platoon' ? $this->bravoPlatoon : $this->bravoSection), 4242);

        $this->actingAs($leader)->get('/settings/templates')->assertInertia(fn ($page) => $page->where('company', $this->bravo->id));
    })->with(['company', 'platoon', 'section']);

    it('falls back to the first company the viewer\'s scope reaches into', function () {
        $staff = User::factory()->serving()->create();
        PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $this->bravoSection->id]);

        $this->actingAs($staff)->get('/settings/templates')->assertInertia(fn ($page) => $page->where('company', $this->bravo->id));
    });

    it('still opens the company asked for, when it is in scope', function () {
        $leader = templatesLeaderOf($this->bravo, 4242);
        PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $this->alpha->id]);

        $this->actingAs($leader)->get('/settings/templates?company='.$this->alpha->id)->assertInertia(fn ($page) => $page->where('company', $this->alpha->id));
    });

    it('lists the preview sections in designation order, so the first is the first section of the first platoon', function () {
        $this->actingAs($this->admin)->get('/settings/templates?company='.$this->alpha->id)->assertInertia(fn ($page) => $page
            ->where('previewSections.0.id', $this->alphaFirst->id)
            ->where('previewSections.1.id', $this->alphaSecond->id)
            ->where('previewSections.2.id', $this->alphaLate->id));
    });
});

describe("the company's command manages its own templates (#375)", function () {
    beforeEach(function () {
        $units = app(UnitRepository::class);
        $this->company->delete();
        $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', null);
        $this->alpha = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
        $this->bravo = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', $battalion->id);
        $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->alpha->id);
        $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
        $this->co = templatesLeaderOf($this->alpha, 5101);
        $this->bravoCo = templatesLeaderOf($this->bravo, 5102);
        $this->sl = templatesLeaderOf($this->section, 5103);
        $this->standard = authorTemplate($this, 'standard', $this->alpha)->template;
        $this->bravoOwn = authorTemplate($this, 'bravo', $this->bravo)->template;
        $this->default = $this->repo->regimentDefault(ArtifactType::RollcallBbcode);
    });

    it('shows the company command its templates beside the regiment default, with the controls', function () {
        $this->actingAs($this->co)->get('/settings/templates')->assertOk()->assertInertia(fn ($page) => $page
            ->where('company', $this->alpha->id)
            ->where('templates.0.id', $this->default->id)
            ->where('templates.1', ['id' => $this->standard->id, 'name' => 'Company template', 'isRegimentDefault' => false, 'enabled' => true, 'isCompanyActive' => true, 'version' => 1])
            ->has('templates', 2)
            ->where('template', $this->standard->id)
            ->where('canManage', true)
            ->where('canManageCompany', true));

        // The regiment default is in every company's list, but only an Admin edits it.
        $this->actingAs($this->co)->get("/settings/templates?template={$this->default->id}")->assertInertia(fn ($page) => $page
            ->where('template', $this->default->id)
            ->where('canManage', false)
            ->where('canManageCompany', true));
        $this->actingAs($this->admin)->get("/settings/templates?company={$this->alpha->id}&template={$this->default->id}")->assertInertia(fn ($page) => $page
            ->where('canManage', true));
    });

    it("shows a section leader the company's templates read-only", function () {
        $this->actingAs($this->sl)->get('/settings/templates')->assertOk()->assertInertia(fn ($page) => $page
            ->where('company', $this->alpha->id)
            ->where('canManage', false)
            ->where('canManageCompany', false));
    });

    it("answers 404 for a template that is not the company's own or the default, existing or not", function () {
        $this->actingAs($this->co)->get("/settings/templates?template={$this->bravoOwn->id}")->assertNotFound();
        $this->actingAs($this->co)->get('/settings/templates?template=999999')->assertNotFound();
    });

    it('lets the company command add, edit, switch and choose its templates, each audited', function () {
        $this->actingAs($this->co)->post('/settings/templates/new', ['company_id' => $this->alpha->id, 'name' => 'Training week', 'body' => 'training', 'reason' => 'For training weeks'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $training = $this->repo->templatesOf($this->alpha->id, ArtifactType::RollcallBbcode)->firstWhere('name', 'Training week');

        $this->post('/settings/templates', ['template_id' => $training->id, 'body' => 'training v2', 'reason' => 'tweak'])->assertSessionHasNoErrors();
        $this->post("/settings/templates/{$this->standard->id}/switch", ['enabled' => false])->assertSessionHasNoErrors();
        $this->post("/settings/templates/{$training->id}/company-active")->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.templates', ['company' => $this->alpha->id, 'template' => $training->id]));

        expect($this->repo->findTemplate($training->id)->activeVersion->body)->toBe('training v2')
            ->and($this->standard->refresh()->enabled)->toBeFalse()
            ->and($this->repo->choiceFor($this->alpha->id, ArtifactType::RollcallBbcode)->template_id)->toBe($training->id)
            ->and(AuditLogEntry::query()->orderBy('id')->pluck('action')->all())->toBe(['template.created', 'template_version.authored', 'template.switched_off', 'template.company_active_set']);
    });

    it("refuses another company's command, a section leader and the regiment default to a CO, writing nothing", function () {
        $this->actingAs($this->bravoCo)->post('/settings/templates/new', ['company_id' => $this->alpha->id, 'name' => 'X', 'body' => 'x', 'reason' => 'why'])->assertForbidden();
        $this->post('/settings/templates', ['template_id' => $this->standard->id, 'body' => 'x', 'reason' => 'why'])->assertForbidden();
        $this->post("/settings/templates/{$this->standard->id}/switch", ['enabled' => false])->assertForbidden();
        $this->post("/settings/templates/{$this->standard->id}/company-active")->assertForbidden();
        $this->post("/settings/templates/{$this->standard->active_version_id}/activate", ['reason' => 'why'])->assertForbidden();
        $this->actingAs($this->sl)->post("/settings/templates/{$this->standard->id}/switch", ['enabled' => false])->assertForbidden();
        $this->actingAs($this->co)->post('/settings/templates', ['template_id' => $this->default->id, 'body' => 'x', 'reason' => 'why'])->assertForbidden();

        expect(AuditLogEntry::query()->count())->toBe(0)->and($this->standard->refresh()->enabled)->toBeTrue();
    });

    it('answers a missing id like a forbidden one for anyone but an Admin', function (string $path, array $body) {
        $this->actingAs($this->co)->post(sprintf($path, 999999), $body)->assertForbidden();
        $this->actingAs($this->admin)->post(sprintf($path, 999999), $body)->assertNotFound();
    })->with([
        'switch' => ['/settings/templates/%d/switch', ['enabled' => false]],
        'company default' => ['/settings/templates/%d/company-active', []],
        'activate' => ['/settings/templates/%d/activate', ['reason' => 'why']],
    ]);

    it('answers a missing company or template in a form like a forbidden one for anyone but an Admin', function () {
        $this->actingAs($this->co)->post('/settings/templates/new', ['company_id' => 999999, 'name' => 'X', 'body' => 'x', 'reason' => 'why'])->assertForbidden();
        $this->post('/settings/templates', ['template_id' => 999999, 'body' => 'x', 'reason' => 'why'])->assertForbidden();
        $this->actingAs($this->admin)->post('/settings/templates/new', ['company_id' => 999999, 'name' => 'X', 'body' => 'x', 'reason' => 'why'])->assertSessionHasErrors('company_id');
    });

    it('turns a refused name, body or reason into a form error on its field', function (array $payload, string $field) {
        $this->actingAs($this->co)->post('/settings/templates/new', $payload + ['company_id' => $this->alpha->id, 'name' => 'New', 'body' => 'body', 'reason' => 'why'])
            ->assertSessionHasErrors($field);
    })->with([
        'a taken name' => [['name' => 'company template'], 'name'],
        'a long name' => [['name' => str_repeat('n', 61)], 'name'],
        'a bad body' => [['body' => '{{colour}}'], 'body'],
        'no reason' => [['reason' => ''], 'reason'],
    ]);

    it('refuses a unit that is not a company with a form error on the company', function () {
        $platoon = app(UnitRepository::class)->tree()->firstWhere('callsign', '1/A/1-7');

        $this->actingAs($this->admin)->post('/settings/templates/new', ['company_id' => $platoon->id, 'name' => 'X', 'body' => 'b', 'reason' => 'r'])
            ->assertSessionHasErrors('company_id');
    });

    it('refuses to switch the regiment default off with a form error', function () {
        $this->actingAs($this->admin)->post("/settings/templates/{$this->default->id}/switch", ['enabled' => false])->assertSessionHasErrors('template_id');
    });
});

describe("a section's own templates on the page", function () {
    beforeEach(function () {
        $units = app(UnitRepository::class);
        $this->company->delete();
        $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', null);
        $this->alpha = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
        $this->bravo = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', $battalion->id);
        $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->alpha->id);
        $this->first = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
        $this->second = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $platoon->id);
        $bravoPlatoon = $units->create(UnitType::Platoon, '1st Platoon', '1/B/1-7', $this->bravo->id);
        $this->bravoSection = $units->create(UnitType::Section, '1st Section', '1/1/B/1-7', $bravoPlatoon->id);
        $this->co = templatesLeaderOf($this->alpha, 5201);
        $this->sl = templatesLeaderOf($this->first, 5202);
        $this->standard = authorTemplate($this, 'standard', $this->alpha)->template;
    });

    it("offers the company's sections, and opens a section's own templates alone, with its leader's controls", function () {
        $own = $this->repo->createTemplate($this->first->id, ArtifactType::RollcallBbcode, 'Our layout', 'our body', $this->admin->id);

        $this->actingAs($this->sl)->get('/settings/templates')->assertInertia(fn ($page) => $page
            ->where('company', $this->alpha->id)
            ->where('section', null)
            ->where('sections.0.id', $this->first->id)
            ->where('canManageCompany', false)
            ->where('canManageSection', false));

        $this->get('/settings/templates?section='.$this->first->id)->assertOk()->assertInertia(fn ($page) => $page
            ->where('company', $this->alpha->id)
            ->where('section', $this->first->id)
            ->has('templates', 1)
            ->where('templates.0.id', $own->id)
            ->where('templates.0.isCompanyActive', false)
            ->where('template', $own->id)
            ->where('active.body', 'our body')
            ->where('canManage', true)
            ->where('canManageCompany', false)
            ->where('canManageSection', true)
            ->has('previewSections', 1)
            ->where('previewSections.0.id', $this->first->id));
    });

    it("lists no section's templates in the company's list", function () {
        $this->repo->createTemplate($this->first->id, ArtifactType::RollcallBbcode, 'Our layout', 'our body', $this->admin->id);

        $this->actingAs($this->co)->get('/settings/templates')->assertInertia(fn ($page) => $page
            ->has('templates', 2)
            ->where('templates.1.id', $this->standard->id));
    });

    it('opens a section with none of its own on an empty list', function () {
        $this->actingAs($this->co)->get('/settings/templates?section='.$this->first->id)->assertInertia(fn ($page) => $page
            ->where('templates', [])
            ->where('template', null)
            ->where('active', null)
            ->where('canManageSection', true));
    });

    it('lets a section leader add, edit and roll back their own section\'s template through the page, audited', function () {
        $this->actingAs($this->sl)->post('/settings/templates/new', ['section_id' => $this->first->id, 'name' => 'Ours', 'body' => 'one', 'reason' => 'why'])
            ->assertSessionHasNoErrors();
        $own = $this->repo->templatesOf($this->first->id, ArtifactType::RollcallBbcode)->sole();

        $this->post('/settings/templates', ['template_id' => $own->id, 'body' => 'two', 'reason' => 'why'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.templates', ['section' => $this->first->id, 'template' => $own->id]));
        $first = $this->repo->historyOf($own)->firstWhere('version', 1);
        $this->post("/settings/templates/{$first->id}/activate", ['reason' => 'roll back'])->assertSessionHasNoErrors();

        expect($own->section_id)->toBe($this->first->id)
            ->and($own->company_id)->toBeNull()
            ->and($this->repo->findTemplate($own->id)->activeVersion->body)->toBe('one')
            ->and($this->repo->historyOf($own)->pluck('section_id')->unique()->all())->toBe([$this->first->id])
            ->and(AuditLogEntry::query()->orderBy('id')->pluck('action')->all())->toBe(['template.created', 'template_version.authored', 'template_version.activated']);
    });

    it("refuses another section's leader, with nothing written, and the switch and company default on a section's template", function () {
        $own = $this->repo->createTemplate($this->first->id, ArtifactType::RollcallBbcode, 'Ours', 'one', $this->admin->id);
        $otherSl = templatesLeaderOf($this->second, 5203);

        $this->actingAs($otherSl)->post('/settings/templates/new', ['section_id' => $this->first->id, 'name' => 'X', 'body' => 'b', 'reason' => 'r'])->assertForbidden();
        $this->post('/settings/templates', ['template_id' => $own->id, 'body' => 'theirs', 'reason' => 'why'])->assertForbidden();

        $this->actingAs($this->co)->post("/settings/templates/{$own->id}/switch", ['enabled' => false])->assertSessionHasErrors('template_id');
        $this->post("/settings/templates/{$own->id}/company-active")->assertSessionHasErrors('template_id');

        expect(AuditLogEntry::query()->count())->toBe(0);
    });

    it('answers a section outside the scope, another company\'s and a missing one alike for a non-Admin, and a missing one with a 404 for an Admin', function () {
        $this->actingAs($this->sl)->get('/settings/templates?section='.$this->bravoSection->id)->assertForbidden();
        $this->get('/settings/templates?section=999999')->assertForbidden();
        $this->get('/settings/templates?section='.$this->alpha->id)->assertForbidden();
        $this->actingAs($this->admin)->get('/settings/templates?section=999999')->assertNotFound();
        $this->get('/settings/templates?section='.$this->bravoSection->id)->assertOk()->assertInertia(fn ($page) => $page->where('company', $this->bravo->id));
    });

    it("answers another section's template by id with a 404", function () {
        $theirs = $this->repo->createTemplate($this->second->id, ArtifactType::RollcallBbcode, 'Theirs', 'b', $this->admin->id);

        $this->actingAs($this->sl)->get("/settings/templates?section={$this->first->id}&template={$theirs->id}")->assertNotFound();
    });

    it('shows a staff grant the section\'s templates read-only', function () {
        $own = $this->repo->createTemplate($this->first->id, ArtifactType::RollcallBbcode, 'Ours', 'one', $this->admin->id);
        $staff = User::factory()->serving()->create();
        PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $this->alpha->id]);

        $this->actingAs($staff)->get('/settings/templates?section='.$this->first->id)->assertOk()->assertInertia(fn ($page) => $page
            ->where('template', $own->id)
            ->where('canManage', false)
            ->where('canManageSection', false));
        $this->post('/settings/templates/new', ['section_id' => $this->first->id, 'name' => 'X', 'body' => 'b', 'reason' => 'r'])->assertForbidden();
    });

    it('refuses a unit that is not a section, for an Admin, with a form error on the section', function () {
        $this->actingAs($this->admin)->post('/settings/templates/new', ['section_id' => $this->first->parent_id, 'name' => 'X', 'body' => 'b', 'reason' => 'r'])
            ->assertSessionHasErrors('section_id');
        $this->post('/settings/templates/new', ['section_id' => 999999, 'name' => 'X', 'body' => 'b', 'reason' => 'r'])->assertSessionHasErrors('section_id');
    });

    it('has no battalion switch any more: no route, no prop', function () {
        $this->actingAs($this->admin)->post("/settings/templates/policy/{$this->alpha->id}", ['company_edits' => false])->assertNotFound();

        $this->get('/settings/templates')->assertInertia(fn ($page) => $page->missing('policy'));

        expect(Route::has('settings.templates.policy'))->toBeFalse()
            ->and(Schema::hasTable('company_template_policies'))->toBeFalse();
    });
});

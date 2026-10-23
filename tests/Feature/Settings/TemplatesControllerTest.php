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
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\FireteamRepository;
use App\Repositories\TemplateVersionRepository;
use App\Rollcall\TemplateSyntax;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// Story 3.5: the Admin template screen. Viewing is open; every write is Admin-only on the server (AD-6, AD-16, AD-17).

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
    return app(AuthorTemplateVersion::class)->handle($test->admin, $company ?? $test->company, ArtifactType::RollcallBbcode, $body, 'seed');
}

describe('viewing', function () {
    it('requires authentication', function () {
        $this->get('/settings/templates')->assertRedirect(route('login'));
    });

    it('shows any authenticated user the active template and its history, read-only', function () {
        authorTemplate($this, 'first body');
        authorTemplate($this, 'second body');

        $this->actingAs(User::factory()->serving()->create())->get('/settings/templates')->assertOk()->assertInertia(fn ($page) => $page
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

    it('says plainly when a company has no template yet', function () {
        $this->actingAs($this->admin)->get('/settings/templates')->assertInertia(fn ($page) => $page
            ->where('active', null)
            ->where('history', []));
    });

    it('renders with no companies at all', function () {
        $this->company->delete();

        $this->actingAs($this->admin)->get('/settings/templates')->assertOk()->assertInertia(fn ($page) => $page
            ->where('companies', [])
            ->where('company', null)
            ->where('active', null));
    });

    it('follows a view-as preview for the controls it shows, and the real flag for writes', function () {
        $leader = Unit::factory()->create(['type' => UnitType::Section]);
        $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $leader->id])->assertSessionHasNoErrors();

        $this->get('/settings/templates')->assertInertia(fn ($page) => $page->where('isAdmin', false));
    });
});

describe('saving a new version', function () {
    it('lets an Admin save an edit, which becomes version 1 and the active one', function () {
        $this->actingAs($this->admin)->post('/settings/templates', [
            'company_id' => $this->company->id,
            'body' => '[CENTER]{{unit}}[/CENTER]',
            'reason' => 'First authoring',
        ])->assertRedirect(route('settings.templates', ['company' => $this->company->id]));

        $active = $this->repo->activeFor($this->company->id, ArtifactType::RollcallBbcode);

        expect($active->version)->toBe(1)
            ->and($active->body)->toBe('[CENTER]{{unit}}[/CENTER]')
            ->and(AuditLogEntry::query()->sole()->reason)->toBe('First authoring');
    });

    it('refuses a non-Admin on the server, whatever the page showed, and writes nothing', function () {
        $this->actingAs(User::factory()->serving()->create())->post('/settings/templates', [
            'company_id' => $this->company->id,
            'body' => 'body',
            'reason' => 'why',
        ])->assertForbidden();

        expect(TemplateVersion::query()->count())->toBe(0)
            ->and(AuditLogEntry::query()->count())->toBe(0);
    });

    it('refuses a signed-out request', function () {
        $this->post('/settings/templates', ['company_id' => $this->company->id, 'body' => 'b', 'reason' => 'r'])->assertRedirect(route('login'));
    });

    it('refuses even a view-as preview of a leader: the write reads the real Admin flag, and the previewed state is read-only', function () {
        $leader = Unit::factory()->create(['type' => UnitType::Section]);
        $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $leader->id])->assertSessionHasNoErrors();

        $this->post('/settings/templates', ['company_id' => $this->company->id, 'body' => 'b', 'reason' => 'r']);

        expect(TemplateVersion::query()->count())->toBe(0);
    });

    it('validates the company, body and reason', function (array $payload, string $field) {
        $this->actingAs($this->admin)->post('/settings/templates', $payload + ['company_id' => $this->company->id, 'body' => 'body', 'reason' => 'why'])
            ->assertSessionHasErrors($field);

        expect(TemplateVersion::query()->count())->toBe(0);
    })->with([
        'no body' => [['body' => ''], 'body'],
        'no reason' => [['reason' => ''], 'reason'],
        'reason too long' => [['reason' => str_repeat('r', 1001)], 'reason'],
        'unknown company' => [['company_id' => 999999], 'company_id'],
        'body too long' => [['body' => str_repeat('b', AuthorTemplateVersion::MAX_BODY_LENGTH + 1)], 'body'],
    ]);

    it('refuses a unit that is not a company with a form error on the company', function () {
        $platoon = Unit::factory()->create(['type' => UnitType::Platoon]);

        $this->actingAs($this->admin)->post('/settings/templates', ['company_id' => $platoon->id, 'body' => 'b', 'reason' => 'r'])
            ->assertSessionHasErrors('company_id');
    });

    it('keeps the earlier version when an Admin saves a second edit', function () {
        $first = authorTemplate($this, 'first body');

        $this->actingAs($this->admin)->post('/settings/templates', ['company_id' => $this->company->id, 'body' => 'second body', 'reason' => 'tweak']);

        expect(TemplateVersion::query()->count())->toBe(2)
            ->and($this->repo->find($first->id)->body)->toBe('first body')
            ->and($this->repo->activeFor($this->company->id, ArtifactType::RollcallBbcode)->body)->toBe('second body');
    });
});

describe('making an older version active', function () {
    it('lets an Admin roll back, with a reason, and writes no new version', function () {
        $first = authorTemplate($this, 'first body');
        authorTemplate($this, 'second body');

        $this->actingAs($this->admin)->post("/settings/templates/{$first->id}/activate", ['reason' => 'The edit broke the table'])
            ->assertRedirect(route('settings.templates', ['company' => $this->company->id]));

        expect($this->repo->activeFor($this->company->id, ArtifactType::RollcallBbcode)->id)->toBe($first->id)
            ->and(TemplateVersion::query()->count())->toBe(2);
    });

    it('refuses a non-Admin on the server and moves nothing', function () {
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
                ['id' => $this->section->id, 'designation' => '2/1/A/1-7', 'name' => '2nd Section', 'platoon' => '1/A/1-7'],
                ['id' => $this->sibling->id, 'designation' => '3/1/A/1-7', 'name' => '3rd Section', 'platoon' => '1/A/1-7'],
            ]));
    });

    it('offers anyone else only the sections under the company in their scope', function () {
        $this->actingAs($this->leader)->get('/settings/templates?company='.$this->alpha->id)->assertInertia(fn ($page) => $page
            ->has('previewSections', 1)
            ->where('previewSections.0.id', $this->section->id));

        $this->actingAs($this->leader)->get('/settings/templates?company='.$this->bravo->id)->assertInertia(fn ($page) => $page
            ->where('previewSections', []));
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

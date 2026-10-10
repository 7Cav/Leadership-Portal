<?php

use App\Models\ArtifactType;
use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\Loa;
use App\Models\PortalRoleGrant;
use App\Models\SectionPractice;
use App\Models\TemplateVersion;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

// Story 3.11: the chain binds end to end. A company's active template is resolved on every render, so one Admin edit
// changes the output of every section under that company on the next load, and nothing else. "Now" is Thursday 2026-10-08.
// Fixture tree: 7CAV ─ 1-7 ─┬─ A/1-7 ─┬─ 1/A ─┬─ 1/1/A (section)
//                           │         │       └─ 2/1/A (section)
//                           │         └─ 2/A ─── 1/2/A (section)
//                           └─ B/1-7 ─── 1/B ─── 1/1/B (section)

function bindTrooper(Unit $section, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

function bindOpen($test, Unit $section)
{
    return $test->get(route('rollcall.show', $section->id));
}

function bindTemplate($test, Unit $section): array
{
    return bindOpen($test, $section)->viewData('page')['props']['template'];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));
    $units = app(UnitRepository::class);

    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $this->alpha = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
    $this->bravo = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', $battalion->id);
    $alphaPlatoon1 = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->alpha->id);
    $alphaPlatoon2 = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->alpha->id);
    $bravoPlatoon = $units->create(UnitType::Platoon, '1st Platoon', '1/B/1-7', $this->bravo->id);
    $this->alphaSections = [
        $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $alphaPlatoon1->id),
        $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $alphaPlatoon1->id),
        $units->create(UnitType::Section, '1st Section, 2nd Platoon', '1/2/A/1-7', $alphaPlatoon2->id),
    ];
    $this->bravoSection = $units->create(UnitType::Section, '1st Section', '1/1/B/1-7', $bravoPlatoon->id);

    $this->admin = User::factory()->admin()->create();
    $this->versions = app(TemplateVersionRepository::class);
    $this->type = ArtifactType::RollcallBbcode;
    $this->author = fn (Unit $company, string $body) => $this->versions->author($company->id, $this->type, $body, $this->admin->id);
});

describe('one edit reaches every section under the company', function () {
    it('shows the new version in every section under the company on the next render, and none under another', function () {
        ($this->author)($this->alpha, '[B]alpha one[/B]');
        $bravoVersion = ($this->author)($this->bravo, '[B]bravo one[/B]');
        $this->actingAs($this->admin);

        foreach ($this->alphaSections as $section) {
            expect(bindTemplate($this, $section)['body'])->toBe('[B]alpha one[/B]');
        }

        $edited = ($this->author)($this->alpha, '[B]alpha two[/B]');

        foreach ($this->alphaSections as $section) {
            $template = bindTemplate($this, $section);

            expect($template['body'])->toBe('[B]alpha two[/B]', "section {$section->name}")
                ->and($template['versionId'])->toBe($edited->id)
                ->and($template['version'])->toBe(2);
        }

        expect(bindTemplate($this, $this->bravoSection)['versionId'])->toBe($bravoVersion->id);
    });

    it('applies an Admin\'s edit made through the settings screen to the very next rollcall load', function () {
        ($this->author)($this->alpha, '[B]alpha one[/B]');
        $this->actingAs($this->admin);
        expect(bindTemplate($this, $this->alphaSections[0])['version'])->toBe(1);

        $this->post('/settings/templates', ['company_id' => $this->alpha->id, 'body' => '[B]{{unit}} two[/B]', 'reason' => 'Standard changed'])->assertRedirect();

        foreach ($this->alphaSections as $section) {
            expect(bindTemplate($this, $section))->toMatchArray(['version' => 2, 'body' => '[B]{{unit}} two[/B]', 'state' => 'resolved']);
        }
    });

    it('lets two companies hold different standards at once, each edited on its own', function () {
        ($this->author)($this->alpha, 'alpha {{unit}}');
        ($this->author)($this->bravo, 'bravo {{unit}}');
        ($this->author)($this->bravo, 'bravo {{week}}');
        $this->actingAs($this->admin);

        expect(bindTemplate($this, $this->alphaSections[1]))->toMatchArray(['body' => 'alpha {{unit}}', 'version' => 1])
            ->and(bindTemplate($this, $this->bravoSection))->toMatchArray(['body' => 'bravo {{week}}', 'version' => 2]);
    });

    it('shows a rollback on the next render too', function () {
        $first = ($this->author)($this->alpha, 'first');
        ($this->author)($this->alpha, 'second');
        $this->actingAs($this->admin);
        expect(bindTemplate($this, $this->alphaSections[0])['body'])->toBe('second');

        $this->post("/settings/templates/{$first->id}/activate", ['reason' => 'The second edit broke the table'])->assertRedirect();

        expect(bindTemplate($this, $this->alphaSections[2])['body'])->toBe('first');
    });

    it('resolves at render time, so a company that gets its first template shows it without anything else changing', function () {
        $this->actingAs($this->admin);
        expect(bindTemplate($this, $this->bravoSection)['state'])->toBe('no_active_template');

        ($this->author)($this->bravo, 'now there is one');

        expect(bindTemplate($this, $this->bravoSection))->toMatchArray(['state' => 'resolved', 'body' => 'now there is one']);
    });

    it('says no active template for one company while another has one', function () {
        ($this->author)($this->alpha, 'alpha standard');
        $this->actingAs($this->admin);

        expect(bindTemplate($this, $this->alphaSections[0])['state'])->toBe('resolved')
            ->and(bindTemplate($this, $this->bravoSection)['state'])->toBe('no_active_template');
    });
});

describe('the leader has no way to edit it', function () {
    it('refuses a section leader\'s attempt to write a template, whatever they send', function () {
        $leader = User::factory()->create();
        PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $this->alphaSections[0]->id]);

        $this->actingAs($leader)->post('/settings/templates', ['company_id' => $this->alpha->id, 'body' => 'mine', 'reason' => 'why'])->assertForbidden();
        $version = ($this->author)($this->alpha, 'standard');
        $this->post("/settings/templates/{$version->id}/activate", ['reason' => 'why'])->assertForbidden();

        expect(TemplateVersion::query()->count())->toBe(1);
    });

    it('offers no template-writing route under the rollcall, and the page carries no authoring control', function () {
        $rollcallRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'rollcall'));

        expect($rollcallRoutes->map(fn ($route) => $route->getName())->sort()->values()->all())->toBe(['rollcall.index', 'rollcall.show', 'rollcall.store']);

        $leader = User::factory()->create();
        PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $this->alphaSections[0]->id]);
        ($this->author)($this->alpha, 'standard');

        $props = bindOpen($this->actingAs($leader), $this->alphaSections[0])->viewData('page')['props'];

        expect($props)->not->toHaveKeys(['isAdmin', 'companies', 'history', 'maxBodyLength']);
    });
});

describe('the flow composes within the leader\'s scope', function () {
    it('opens a section, pre-fills it, marks it and renders from the active template, with the same version throughout', function () {
        $version = ($this->author)($this->alpha, '[B]{{unit}}[/B]{{#troopers}}{{name}}{{/troopers}}');
        $section = $this->alphaSections[0];
        $carried = bindTrooper($section, ['username' => 'Carried.P']);
        $onLeave = bindTrooper($section, ['username' => 'Away.L']);
        Loa::factory()->create(['trooper_id' => $onLeave->id, 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-30']);
        $practice = SectionPractice::factory()->create(['section_id' => $section->id, 'week_start' => '2026-09-26']);
        Attendance::factory()->create(['trooper_id' => $carried->id, 'section_practice_id' => $practice->id, 'status' => AttendanceStatus::Present]);

        $leader = User::factory()->create();
        PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $section->id]);
        $this->actingAs($leader);

        $before = bindOpen($this, $section)->viewData('page')['props'];
        $rows = collect($before['troopers'])->keyBy('id');

        expect($before['template']['versionId'])->toBe($version->id)
            ->and($rows[$carried->id]['practice']['origin'])->toBe('carried')
            ->and($rows[$onLeave->id]['practice']['origin'])->toBe('leave');

        $this->post(route('rollcall.store', $section->id), ['week' => '2026-10-03', 'marks' => [['trooper_id' => $carried->id, 'status' => 'present']]])->assertRedirect();

        $after = bindOpen($this, $section)->viewData('page')['props'];

        expect(collect($after['troopers'])->keyBy('id')[$carried->id]['practice'])->toBe(['value' => 'present', 'origin' => 'saved', 'source' => 'manual'])
            ->and($after['template']['versionId'])->toBe($version->id);
    });

    it('serves no section outside the leader\'s scope, however many templates exist', function () {
        ($this->author)($this->alpha, 'alpha standard');
        ($this->author)($this->bravo, 'bravo standard');
        $leader = User::factory()->create();
        PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $this->alphaSections[0]->id]);
        $this->actingAs($leader);

        bindOpen($this, $this->alphaSections[0])->assertOk();
        bindOpen($this, $this->alphaSections[1])->assertForbidden();
        bindOpen($this, $this->bravoSection)->assertForbidden();
    });

    it('leaves marking and submitting unaffected by a template change between load and submit', function () {
        ($this->author)($this->alpha, 'first');
        $section = $this->alphaSections[0];
        $trooper = bindTrooper($section);
        $leader = User::factory()->create();
        PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $section->id]);
        $this->actingAs($leader);

        $loaded = bindOpen($this, $section)->viewData('page')['props']['template']['versionId'];
        ($this->author)($this->alpha, 'second, activated after the page loaded');

        $this->post(route('rollcall.store', $section->id), ['week' => '2026-10-03', 'marks' => [['trooper_id' => $trooper->id, 'status' => 'present']]])->assertRedirect();

        expect(Attendance::query()->sole()->status)->toBe(AttendanceStatus::Present)
            ->and(AuditLogEntry::query()->where('action', 'attendance.marked')->count())->toBe(1)
            ->and(bindOpen($this, $section)->viewData('page')['props']['template']['versionId'])->not->toBe($loaded);
    });

    it('renders every section from its own company\'s version when each is under a different company', function () {
        $alpha = ($this->author)($this->alpha, 'alpha');
        $bravo = ($this->author)($this->bravo, 'bravo');
        $this->actingAs($this->admin);

        $versionIds = collect([...$this->alphaSections, $this->bravoSection])->map(fn (Unit $s) => bindTemplate($this, $s)['versionId'])->all();

        expect($versionIds)->toBe([$alpha->id, $alpha->id, $alpha->id, $bravo->id]);
    });
});

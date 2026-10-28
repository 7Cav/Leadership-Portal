<?php

use App\Models\ArtifactType;
use App\Models\AuditLogEntry;
use App\Models\FireteamAssignment;
use App\Models\FireteamRole;
use App\Models\FireteamSetting;
use App\Models\GamePlayerDismissal;
use App\Models\PlatoonTrainingTopic;
use App\Models\PortalRoleGrant;
use App\Models\PracticeCapturePlayer;
use App\Models\SectionPractice;
use App\Models\TemplateVersion;
use App\Models\Trooper;
use App\Models\TrooperCourse;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\TemplateVersionRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

function auditAbout($test, Model $target, string $action = 'unit.created'): AuditLogEntry
{
    return AuditLogEntry::factory()->create([
        'actor_id' => $test->actor->id,
        'action' => $action,
        'target_type' => class_basename($target),
        'target_id' => (string) $target->getKey(),
    ]);
}

/**
 * A dismissal of one player, captured by a practice of each section given, keyed by the practice's week.
 *
 * @param  array<string, Unit>  $capturedBy  `Y-m-d` week => section
 */
function auditDismissal($test, array $capturedBy, string $platformId = '7656119000000001', string $name = 'Stranger'): GamePlayerDismissal
{
    foreach ($capturedBy as $week => $section) {
        $practice = SectionPractice::factory()->create(['section_id' => $section->id, 'week_start' => CarbonImmutable::parse("{$week} 00:00:00", 'UTC')]);
        PracticeCapturePlayer::query()->create(['section_practice_id' => $practice->id, 'game_id' => game('hll')->id, 'player_name' => $name, 'platform_id' => $platformId]);
    }

    return GamePlayerDismissal::query()->create(['game_id' => game('hll')->id, 'platform_id' => $platformId, 'player_name' => $name, 'dismissed_by' => $test->actor->id]);
}

/**
 * Each entry's `link` on the page, by entry id.
 *
 * @return array<int, ?array{href: string, label: string}>
 */
function auditLinksOn($test, string $query = ''): array
{
    $links = null;
    $test->actingAs($test->admin)->get('/settings/audit-log'.$query)->assertOk()->assertInertia(function ($page) use (&$links) {
        $links = collect($page->toArray()['props']['entries']['data'])->mapWithKeys(fn ($entry) => [$entry['id'] => $entry['link']])->all();
    });

    return $links;
}

it('requires authentication to view the audit log', function () {
    $this->get('/settings/audit-log')->assertRedirect(route('login'));
});

it('refuses a non-Admin server-side with 403 (AD-6) — the whole screen is Admin-only', function () {
    $user = User::factory()->serving()->create();

    $this->actingAs($user)->get('/settings/audit-log')->assertForbidden();
});

it('renders the audit log for an Admin', function () {
    $admin = User::factory()->admin()->create();
    $actor = User::factory()->create(['username' => 'jgryspeert']);
    AuditLogEntry::factory()->create([
        'actor_id' => $actor->id,
        'action' => 'unit.created',
        'target_type' => 'Unit',
        'target_id' => '1',
        'reason' => 'Reorganizing the chain of command',
    ]);

    $this->actingAs($admin)->get('/settings/audit-log')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/AuditLog')
            ->has('entries.data', 1)
            ->where('entries.data.0.actor', 'jgryspeert')
            ->where('entries.data.0.action', 'unit.created')
            ->where('entries.data.0.target_type', 'Unit')
            ->where('entries.data.0.target_id', '1')
            ->where('entries.data.0.reason', 'Reorganizing the chain of command')
            ->has('entries.data.0.content_digest')
            ->has('entries.data.0.created_at'));
});

it('narrows the result set by actor filter (AC #2)', function () {
    $admin = User::factory()->admin()->create();
    $wanted = User::factory()->create();
    $other = User::factory()->create();
    AuditLogEntry::factory()->create(['actor_id' => $wanted->id]);
    AuditLogEntry::factory()->create(['actor_id' => $other->id]);

    $this->actingAs($admin)->get('/settings/audit-log?actor_id='.$wanted->id)
        ->assertInertia(fn ($page) => $page->has('entries.data', 1));
});

it('narrows the result set by action filter (AC #2)', function () {
    $admin = User::factory()->admin()->create();
    $actor = User::factory()->create();
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'action' => 'unit.created']);
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'action' => 'unit.deleted']);

    $this->actingAs($admin)->get('/settings/audit-log?action=unit.deleted')
        ->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.action', 'unit.deleted'));
});

it('combines actor and action filters with AND (AC #2)', function () {
    $admin = User::factory()->admin()->create();
    $actor = User::factory()->create();
    $other = User::factory()->create();
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'action' => 'unit.created']);
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'action' => 'unit.deleted']);
    AuditLogEntry::factory()->create(['actor_id' => $other->id, 'action' => 'unit.created']);

    $this->actingAs($admin)->get('/settings/audit-log?actor_id='.$actor->id.'&action=unit.created')
        ->assertInertia(fn ($page) => $page->has('entries.data', 1));
});

it('narrows the result set by a date range (AC #2)', function () {
    $admin = User::factory()->admin()->create();
    $actor = User::factory()->create();
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'created_at' => '2026-05-01 12:00:00']);
    $inRange = AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'created_at' => '2026-05-10 12:00:00']);
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'created_at' => '2026-05-20 12:00:00']);

    $this->actingAs($admin)->get('/settings/audit-log?date_from=2026-05-05&date_to=2026-05-15')
        ->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.id', $inRange->id));
});

it('rejects an invalid date filter with a 422 rather than a raw DB error', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/settings/audit-log?date_from=not-a-date')
        ->assertInvalid('date_from');
});

it('rejects a non-numeric actor_id with a 422', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/settings/audit-log?actor_id=abc')
        ->assertInvalid('actor_id');
});

it('carries the actor filter across pagination so page 2 stays filtered (AC #2)', function () {
    $admin = User::factory()->admin()->create();
    $wanted = User::factory()->create();
    $other = User::factory()->create();
    AuditLogEntry::factory()->count(30)->create(['actor_id' => $wanted->id]);
    AuditLogEntry::factory()->count(5)->create(['actor_id' => $other->id]);

    $nextPageUrl = null;

    $this->actingAs($admin)->get('/settings/audit-log?actor_id='.$wanted->id)
        ->assertInertia(function ($page) use (&$nextPageUrl) {
            $page->has('entries.data', 25);
            $nextPageUrl = $page->toArray()['props']['entries']['next_page_url'];
        });

    expect($nextPageUrl)->toContain('actor_id='.$wanted->id);

    $this->actingAs($admin)->get($nextPageUrl)
        ->assertInertia(fn ($page) => $page
            ->has('entries.data', 5)
            ->where('entries.data', fn ($data) => collect($data)->pluck('id')->every(
                fn ($id) => AuditLogEntry::find($id)->actor_id === $wanted->id,
            )));
});

it('passes distinct actor and action options for the filter controls', function () {
    $admin = User::factory()->admin()->create();
    $actor = User::factory()->create(['username' => 'zed']);
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'action' => 'unit.created']);

    $this->actingAs($admin)->get('/settings/audit-log')
        ->assertInertia(fn ($page) => $page
            ->where('actorOptions.0.username', 'zed')
            ->where('actionOptions.0', 'unit.created'));
});

it('creates no audit_log entries of its own (AC #6, read-only)', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/settings/audit-log');

    expect(AuditLogEntry::count())->toBe(0);
});

describe('what each entry changed', function () {
    beforeEach(function () {
        $this->admin = User::factory()->admin()->create();
        $this->actor = User::factory()->create();

        $this->company = Unit::factory()->create(['type' => UnitType::Company, 'name' => 'Alpha Company', 'callsign' => 'A/1-7', 'path' => '700/']);
        $this->platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'parent_id' => $this->company->id, 'name' => '1st Platoon', 'callsign' => '1/A/1-7', 'path' => '700/701/']);
        $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'name' => '2nd Section', 'callsign' => '2/1/A/1-7', 'path' => '700/701/702/']);
        $this->other = Unit::factory()->create(['type' => UnitType::Company, 'name' => 'Bravo Company', 'callsign' => 'B/1-7', 'path' => '710/']);
        $this->otherSection = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->other->id, 'name' => '1st Section', 'callsign' => '1/1/B/1-7', 'path' => '710/711/']);

        $this->trooper = Trooper::factory()->create(['rank_short' => 'SGT', 'username' => 'Vasquez.R']);
        $this->trooper->forceFill(['primary_section_id' => $this->section->id])->save();
        $this->outsider = Trooper::factory()->create(['username' => 'Other.S']);
        $this->outsider->forceFill(['primary_section_id' => $this->otherSection->id])->save();
    });

    it('links each entry to the page that shows what it changed', function () {
        $practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC')]);
        $report = WeeklyReport::factory()->create(['unit_id' => $this->platoon->id, 'week_start' => CarbonImmutable::parse('2026-09-26 00:00:00', 'UTC')]);
        $template = TemplateVersion::factory()->create(['company_id' => $this->company->id, 'version' => 3]);
        $identity = TrooperGameIdentity::factory()->create(['trooper_id' => $this->trooper->id]);
        $course = TrooperCourse::factory()->create(['trooper_id' => $this->trooper->id]);
        $grant = PortalRoleGrant::factory()->create(['unit_id' => $this->section->id]);

        $entries = [
            'practice' => auditAbout($this, $practice, 'attendance.marked'),
            'report' => auditAbout($this, $report, 'weekly_report.sent'),
            'unit' => auditAbout($this, $this->platoon, 'unit.renamed'),
            'trooper' => auditAbout($this, $this->trooper, 'trooper.placed'),
            'identity' => auditAbout($this, $identity, 'game_identity.linked'),
            'course' => auditAbout($this, $course, 'trooper_course.status_set'),
            'template' => auditAbout($this, $template, 'template_version.authored'),
            'grant' => auditAbout($this, $grant, 'portal_role_grant.granted'),
        ];

        $links = auditLinksOn($this);

        expect($links[$entries['practice']->id])->toBe(['href' => "/rollcall/{$this->section->id}?week=2026-10-03", 'label' => '2/1/A/1-7 rollcall, week of 03Oct26'])
            ->and($links[$entries['report']->id])->toBe(['href' => "/reports/{$this->platoon->id}/2026-09-26", 'label' => '1/A/1-7 report, week of 26Sep26'])
            ->and($links[$entries['unit']->id])->toBe(['href' => "/roster/{$this->platoon->id}", 'label' => '1/A/1-7'])
            ->and($links[$entries['trooper']->id])->toBe(['href' => "/troopers/{$this->trooper->id}", 'label' => 'SGT Vasquez.R'])
            ->and($links[$entries['identity']->id])->toBe(['href' => "/troopers/{$this->trooper->id}", 'label' => 'SGT Vasquez.R'])
            ->and($links[$entries['course']->id])->toBe(['href' => "/troopers/{$this->trooper->id}", 'label' => 'SGT Vasquez.R'])
            ->and($links[$entries['template']->id])->toBe(['href' => "/settings/templates?company={$this->company->id}&template={$template->template_id}", 'label' => 'A/1-7 template "Company template", version 3'])
            ->and($links[$entries['grant']->id])->toBe(['href' => '/settings/staff-grants', 'label' => 'Staff grants']);
    });

    it('links the fireteam, training-topic and dismissal entries to the page that shows them', function () {
        $assignment = FireteamAssignment::query()->create(['section_id' => $this->section->id, 'trooper_id' => $this->trooper->id, 'role' => FireteamRole::Leader]);
        $display = FireteamSetting::query()->create(['unit_id' => $this->section->id, 'show_fireteams' => true]);
        $policy = FireteamSetting::query()->create(['unit_id' => $this->company->id, 'show_fireteams' => false]);
        $topic = PlatoonTrainingTopic::query()->create(['unit_id' => $this->platoon->id, 'week_start' => CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC'), 'subject' => 'Room clearing', 'set_by' => $this->actor->id]);
        $dismissal = auditDismissal($this, ['2026-09-26' => $this->otherSection, '2026-10-03' => $this->section]);
        $unseen = GamePlayerDismissal::query()->create(['game_id' => game('hll')->id, 'platform_id' => '7656119000000002', 'player_name' => 'Ghost', 'dismissed_by' => $this->actor->id]);

        $entries = [
            'assignment' => auditAbout($this, $assignment, 'fireteam.leader_set'),
            'display' => auditAbout($this, $display, 'fireteam.options_set'),
            'policy' => auditAbout($this, $policy, 'fireteam.policy_set'),
            'topic' => auditAbout($this, $topic, 'platoon_training_topic.set'),
            'dismissal' => auditAbout($this, $dismissal, 'game_player.dismissed'),
            'unseen' => auditAbout($this, $unseen, 'game_player.dismissed'),
        ];

        $links = auditLinksOn($this);

        expect($links[$entries['assignment']->id])->toBe(['href' => "/rollcall/{$this->section->id}", 'label' => '2/1/A/1-7 fireteams'])
            ->and($links[$entries['display']->id])->toBe(['href' => "/rollcall/{$this->section->id}", 'label' => '2/1/A/1-7 fireteam display'])
            ->and($links[$entries['policy']->id])->toBeNull() // set from any of its sections' rollcalls, no page of its own
            ->and($links[$entries['topic']->id])->toBe(['href' => "/reports/{$this->platoon->id}/2026-10-03", 'label' => '1/A/1-7 training topic, week of 03Oct26'])
            ->and($links[$entries['dismissal']->id])->toBe(['href' => "/rollcall/{$this->section->id}?week=2026-10-03", 'label' => 'Stranger dismissed, 2/1/A/1-7 rollcall, week of 03Oct26'])
            ->and($links[$entries['unseen']->id])->toBeNull();
    });

    it('links nothing when the target is gone or has no page of its own', function () {
        $deleted = AuditLogEntry::factory()->create(['actor_id' => $this->actor->id, 'action' => 'unit.deleted', 'target_type' => 'Unit', 'target_id' => '999999']);
        $user = auditAbout($this, $this->actor, 'user.admin_granted');
        $odd = AuditLogEntry::factory()->create(['actor_id' => $this->actor->id, 'target_type' => 'Unit', 'target_id' => 'not-a-number']);

        $links = auditLinksOn($this);

        expect($links[$deleted->id])->toBeNull()
            ->and($links[$user->id])->toBeNull()
            ->and($links[$odd->id])->toBeNull();
    });

    it('reads the targets once per type for the page, not once per entry', function () {
        $queriesFor = function (int $perType): int {
            AuditLogEntry::query()->delete();
            foreach (range(1, $perType) as $n) {
                $section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'path' => "700/701/7{$perType}{$n}/"]);
                // A fixed week, not the factory's random one: the dismissal below captures this section's practice on 2026-10-03, and a random week lands on it about one run in 500.
                auditAbout($this, SectionPractice::factory()->create(['section_id' => $section->id, 'week_start' => CarbonImmutable::parse('2026-01-03 00:00:00', 'UTC')]), 'attendance.marked');
                auditAbout($this, WeeklyReport::factory()->create(['unit_id' => $section->id]), 'weekly_report.sent');
                auditAbout($this, TemplateVersion::factory()->create(['company_id' => $this->company->id, 'version' => $perType * 10 + $n]), 'template_version.authored');
                auditAbout($this, TrooperGameIdentity::factory()->create(), 'game_identity.linked');
                auditAbout($this, $section);
                auditAbout($this, FireteamAssignment::query()->create(['section_id' => $section->id, 'trooper_id' => Trooper::factory()->create()->id, 'role' => FireteamRole::Leader]), 'fireteam.leader_set');
                auditAbout($this, FireteamSetting::query()->create(['unit_id' => $section->id, 'show_fireteams' => true]), 'fireteam.options_set');
                auditAbout($this, PlatoonTrainingTopic::query()->create(['unit_id' => $section->id, 'week_start' => CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC'), 'subject' => 'Room clearing', 'set_by' => $this->actor->id]), 'platoon_training_topic.set');
                auditAbout($this, auditDismissal($this, ['2026-10-03' => $section], "76561190000{$perType}{$n}"), 'game_player.dismissed');
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            auditLinksOn($this);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        expect($queriesFor(5))->toBe($queriesFor(1));
    });

    it('keeps the entries about a unit and everything under it', function () {
        $inside = [
            auditAbout($this, $this->company),
            auditAbout($this, $this->section, 'unit.moved'),
            auditAbout($this, SectionPractice::factory()->create(['section_id' => $this->section->id]), 'practice.confirmed'),
            auditAbout($this, WeeklyReport::factory()->create(['unit_id' => $this->platoon->id]), 'weekly_report.draft_saved'),
            auditAbout($this, TemplateVersion::factory()->create(['company_id' => $this->company->id]), 'template_version.activated'),
            auditAbout($this, PortalRoleGrant::factory()->create(['unit_id' => $this->platoon->id]), 'portal_role_grant.granted'),
            auditAbout($this, $this->trooper, 'trooper.placed'),
            auditAbout($this, TrooperGameIdentity::factory()->create(['trooper_id' => $this->trooper->id]), 'game_identity.linked'),
            auditAbout($this, TrooperCourse::factory()->create(['trooper_id' => $this->trooper->id]), 'trooper_course.status_set'),
        ];
        // About another company, about nothing in a unit, and a unit that no longer exists.
        auditAbout($this, $this->other);
        auditAbout($this, SectionPractice::factory()->create(['section_id' => $this->otherSection->id]), 'practice.confirmed');
        auditAbout($this, $this->outsider, 'trooper.placed');
        auditAbout($this, TrooperGameIdentity::factory()->create(['trooper_id' => $this->outsider->id]), 'game_identity.linked');
        auditAbout($this, $this->actor, 'user.admin_granted');
        AuditLogEntry::factory()->create(['actor_id' => $this->actor->id, 'target_type' => 'Unit', 'target_id' => '999999']);

        $this->actingAs($this->admin)->get('/settings/audit-log?unit_id='.$this->company->id)->assertInertia(fn ($page) => $page
            ->where('filters.unit_id', (string) $this->company->id)
            ->where('entries.data', fn ($data) => collect($data)->pluck('id')->sort()->values()->all() === collect($inside)->pluck('id')->sort()->values()->all()));
    });

    it('keeps the fireteam, training-topic and dismissal entries about a unit and everything under it', function () {
        $week = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
        $inside = [
            auditAbout($this, FireteamAssignment::query()->create(['section_id' => $this->section->id, 'trooper_id' => $this->trooper->id, 'role' => FireteamRole::Leader]), 'fireteam.leader_set'),
            auditAbout($this, FireteamSetting::query()->create(['unit_id' => $this->section->id, 'show_fireteams' => true]), 'fireteam.options_set'),
            auditAbout($this, FireteamSetting::query()->create(['unit_id' => $this->company->id, 'show_fireteams' => false]), 'fireteam.policy_set'),
            auditAbout($this, PlatoonTrainingTopic::query()->create(['unit_id' => $this->platoon->id, 'week_start' => $week, 'subject' => 'Room clearing', 'set_by' => $this->actor->id]), 'platoon_training_topic.set'),
            auditAbout($this, auditDismissal($this, ['2026-10-03' => $this->section]), 'game_player.dismissed'),
        ];
        // The same kinds about another company, and a dismissal of a player no practice here captured.
        auditAbout($this, FireteamAssignment::query()->create(['section_id' => $this->otherSection->id, 'trooper_id' => $this->outsider->id, 'role' => FireteamRole::Leader]), 'fireteam.leader_set');
        auditAbout($this, FireteamSetting::query()->create(['unit_id' => $this->other->id, 'show_fireteams' => true]), 'fireteam.policy_set');
        auditAbout($this, PlatoonTrainingTopic::query()->create(['unit_id' => $this->other->id, 'week_start' => $week, 'subject' => 'Radio procedure', 'set_by' => $this->actor->id]), 'platoon_training_topic.set');
        auditAbout($this, auditDismissal($this, ['2026-10-03' => $this->otherSection], '7656119000000003', 'Elsewhere'), 'game_player.dismissed');

        $this->actingAs($this->admin)->get('/settings/audit-log?unit_id='.$this->company->id)->assertInertia(fn ($page) => $page
            ->where('entries.data', fn ($data) => collect($data)->pluck('id')->sort()->values()->all() === collect($inside)->pluck('id')->sort()->values()->all()));
    });

    it('narrows to a section without the rest of its company', function () {
        $wanted = auditAbout($this, SectionPractice::factory()->create(['section_id' => $this->section->id]), 'practice.confirmed');
        auditAbout($this, $this->company);
        auditAbout($this, $this->platoon);

        $this->actingAs($this->admin)->get('/settings/audit-log?unit_id='.$this->section->id)->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.id', $wanted->id));
    });

    it('combines the unit filter with the others and keeps it across pages', function () {
        AuditLogEntry::factory()->count(30)->create(['actor_id' => $this->actor->id, 'action' => 'unit.renamed', 'target_type' => 'Unit', 'target_id' => (string) $this->section->id]);
        AuditLogEntry::factory()->create(['actor_id' => $this->actor->id, 'action' => 'unit.created', 'target_type' => 'Unit', 'target_id' => (string) $this->section->id]);
        AuditLogEntry::factory()->count(3)->create(['actor_id' => $this->actor->id, 'action' => 'unit.renamed', 'target_type' => 'Unit', 'target_id' => (string) $this->other->id]);

        $next = null;
        $this->actingAs($this->admin)->get("/settings/audit-log?unit_id={$this->company->id}&action=unit.renamed")->assertInertia(function ($page) use (&$next) {
            $page->where('entries.total', 30);
            $next = $page->toArray()['props']['entries']['next_page_url'];
        });

        expect($next)->toContain('unit_id='.$this->company->id);
        $this->actingAs($this->admin)->get($next)->assertInertia(fn ($page) => $page->has('entries.data', 5));
    });

    it('refuses a unit that does not exist', function () {
        $this->actingAs($this->admin)->get('/settings/audit-log?unit_id=999999')->assertInvalid('unit_id');
    });

    it('offers every unit in tree order, with its depth', function () {
        $this->actingAs($this->admin)->get('/settings/audit-log')->assertInertia(fn ($page) => $page
            ->where('unitOptions', [
                ['id' => $this->company->id, 'label' => 'A/1-7 Alpha Company', 'depth' => 0],
                ['id' => $this->platoon->id, 'label' => '1/A/1-7 1st Platoon', 'depth' => 1],
                ['id' => $this->section->id, 'label' => '2/1/A/1-7 2nd Section', 'depth' => 2],
                ['id' => $this->other->id, 'label' => 'B/1-7 Bravo Company', 'depth' => 0],
                ['id' => $this->otherSection->id, 'label' => '1/1/B/1-7 1st Section', 'depth' => 1],
            ]));
    });
});

describe('template entries (#375)', function () {
    beforeEach(function () {
        $this->admin = User::factory()->admin()->create();
        $this->actor = User::factory()->create();
        $this->company = Unit::factory()->create(['type' => UnitType::Company, 'name' => 'Alpha Company', 'callsign' => 'A/1-7', 'path' => '750/']);
        $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->company->id, 'name' => '1st Section', 'callsign' => '1/1/A/1-7', 'path' => '750/751/']);
    });

    it('links a template, the regiment default, a company default and a section pick to where they are changed', function () {
        $repo = app(TemplateVersionRepository::class);
        $template = $repo->createTemplate($this->company->id, ArtifactType::RollcallBbcode, 'Training week', 'body', $this->actor->id);
        $default = $repo->regimentDefault(ArtifactType::RollcallBbcode);
        $entries = [
            'template' => auditAbout($this, $template, 'template.created'),
            'default' => auditAbout($this, $default->activeVersion, 'template_version.authored'),
            'company' => auditAbout($this, $repo->choiceFor($this->company->id, ArtifactType::RollcallBbcode), 'template.company_active_set'),
            'pick' => auditAbout($this, $repo->choose($this->section, $template, $this->actor->id), 'template.picked'),
        ];

        $links = auditLinksOn($this);

        expect($links[$entries['template']->id])->toBe(['href' => "/settings/templates?company={$this->company->id}&template={$template->id}", 'label' => 'A/1-7 template "Training week"'])
            ->and($links[$entries['default']->id])->toBe(['href' => "/settings/templates?template={$default->id}", 'label' => 'Regiment default template, version 1'])
            ->and($links[$entries['company']->id])->toBe(['href' => "/settings/templates?company={$this->company->id}", 'label' => 'A/1-7 active template'])
            ->and($links[$entries['pick']->id])->toBe(['href' => "/rollcall/{$this->section->id}", 'label' => '1/1/A/1-7 rollcall template']);

        // The unit filter finds them under the company.
        $filtered = auditLinksOn($this, "?unit_id={$this->company->id}");
        expect(array_keys($filtered))->toContain($entries['template']->id, $entries['company']->id, $entries['pick']->id);
    });
});

it("links a section's own template and its versions to the section's templates, and finds them under the section and the company", function () {
    $this->admin = User::factory()->admin()->create();
    $this->actor = User::factory()->create();
    $company = Unit::factory()->create(['type' => UnitType::Company, 'name' => 'Alpha Company', 'callsign' => 'A/1-7', 'path' => '760/']);
    $section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $company->id, 'name' => '1st Section', 'callsign' => '1/1/A/1-7', 'path' => '760/761/']);
    $repo = app(TemplateVersionRepository::class);
    $own = $repo->createTemplate($section->id, ArtifactType::RollcallBbcode, 'Ours', 'body', $this->actor->id);
    $template = auditAbout($this, $own, 'template.created');
    $version = auditAbout($this, $repo->authorVersion($own, 'two', $this->actor->id), 'template_version.authored');

    $links = auditLinksOn($this);

    expect($links[$template->id])->toBe(['href' => "/settings/templates?section={$section->id}&template={$own->id}", 'label' => '1/1/A/1-7 template "Ours"'])
        ->and($links[$version->id])->toBe(['href' => "/settings/templates?section={$section->id}&template={$own->id}", 'label' => '1/1/A/1-7 template "Ours", version 2'])
        ->and(array_keys(auditLinksOn($this, "?unit_id={$section->id}")))->toContain($template->id, $version->id)
        ->and(array_keys(auditLinksOn($this, "?unit_id={$company->id}")))->toContain($template->id, $version->id);
});

it('still lists an entry about the removed battalion template switch, linked to nothing (#376 withdrawn)', function () {
    $this->admin = User::factory()->admin()->create();
    $this->actor = User::factory()->create();
    $entry = AuditLogEntry::factory()->create([
        'actor_id' => $this->actor->id,
        'action' => 'template_policy.set',
        'target_type' => 'CompanyTemplatePolicy',
        'target_id' => '7',
    ]);

    $this->actingAs($this->admin)->get('/settings/audit-log')->assertOk()->assertInertia(fn ($page) => $page
        ->where('entries.data.0.id', $entry->id)
        ->where('entries.data.0.action', 'template_policy.set')
        ->where('entries.data.0.target_type', 'CompanyTemplatePolicy')
        ->where('entries.data.0.link', null));
});

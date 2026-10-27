<?php

use App\Models\AuditLogEntry;
use App\Models\FireteamAssignment;
use App\Models\FireteamOrder;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\FireteamRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// The rollcall's fireteams (the owner's order, 2026-10-16): each trooper's role on the page, the section's display
// options with the company's policy over them, and the audited writes behind the controls.

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $units = app(UnitRepository::class);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);

    $this->slUser = rollcallCommander($this->section, 201, ['username' => 'Leader.S', 'billet' => 'Section Leader 1/1/A/1-7']);
    $this->sl = Trooper::query()->where('cav_id', $this->slUser->cav_id)->sole();
    $this->asl = rollcallPlaced($this->section, ['username' => 'Second.A', 'billet' => 'Assistant Section Leader 1/1/A/1-7']);
    $this->ftl = rollcallPlaced($this->section, ['username' => 'Adams.P']);
    $this->member = rollcallPlaced($this->section, ['username' => 'Baker.Q']);
    $this->loner = rollcallPlaced($this->section, ['username' => 'Cole.R']);
});

function rollcallCommander(Unit $unit, int $positionId, array $trooper = []): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $positionId]);
    $row = Trooper::factory()->create(['cav_position_id' => $positionId, ...$trooper]);

    if ($unit->type === UnitType::Section) {
        $row->forceFill(['primary_section_id' => $unit->id])->save();
    }

    return User::factory()->create(['cav_id' => $row->cav_id]);
}

function rollcallPlaced(Unit $section, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

/** @return array<int, array{role: ?string, leads: bool, fireteamLeaderId: ?int}> */
function rolesOn($page): array
{
    return collect($page->toArray()['props']['troopers'])
        ->mapWithKeys(fn ($t) => [$t['id'] => ['role' => $t['role'], 'leads' => $t['leadsFireteam'], 'fireteamLeaderId' => $t['fireteamLeaderId']]])
        ->sortKeys()
        ->all();
}

it('sends each trooper\'s role and fireteam, with the SL and ASL read from the roster', function () {
    $repo = app(FireteamRepository::class);
    $repo->makeLeader($this->section->id, $this->ftl->id);
    $repo->makeMember($this->section->id, $this->member->id, $this->ftl->id);

    $this->actingAs($this->slUser)->get("/rollcall/{$this->section->id}")
        ->assertOk()
        // Keyed by trooper id, in id order: the page sorts the rows itself (resources/js/lib/fireteams.js).
        ->assertInertia(fn ($page) => expect(rolesOn($page))->toBe([
            $this->sl->id => ['role' => 'sl', 'leads' => false, 'fireteamLeaderId' => null],
            $this->asl->id => ['role' => 'asl', 'leads' => false, 'fireteamLeaderId' => null],
            $this->ftl->id => ['role' => 'ftl', 'leads' => true, 'fireteamLeaderId' => null],
            $this->member->id => ['role' => null, 'leads' => false, 'fireteamLeaderId' => $this->ftl->id],
            $this->loner->id => ['role' => null, 'leads' => false, 'fireteamLeaderId' => null],
        ]) && true);
});

it('reads an FTL who has since become the ASL as the ASL, and a fireteam whose leader left as none', function () {
    $repo = app(FireteamRepository::class);
    $repo->makeLeader($this->section->id, $this->ftl->id);
    $repo->makeMember($this->section->id, $this->member->id, $this->ftl->id);
    $repo->makeLeader($this->section->id, $this->loner->id);
    FireteamAssignment::query()->create(['section_id' => $this->section->id, 'trooper_id' => $this->asl->id, 'role' => 'ftl']);
    // Adams.P moves to another section with the next roster sync.
    $this->ftl->forceFill(['primary_section_id' => Unit::factory()->create(['type' => UnitType::Section])->id])->save();

    $this->actingAs($this->slUser)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => expect(rolesOn($page))->toMatchArray([
            $this->asl->id => ['role' => 'asl', 'leads' => false, 'fireteamLeaderId' => null],
            $this->member->id => ['role' => null, 'leads' => false, 'fireteamLeaderId' => null],
            $this->loner->id => ['role' => 'ftl', 'leads' => true, 'fireteamLeaderId' => null],
        ]) && true);
});

it('defaults to FTLs hidden, all FTLs above the rest, and lets the section\'s leader change them but not the company policy', function () {
    $this->actingAs($this->slUser)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page
            ->where('fireteams.show', false)
            ->where('fireteams.order', 'leaders_first')
            ->where('fireteams.section', ['show' => null, 'order' => null])
            ->where('fireteams.slAslLead', false)
            ->where('fireteams.alphabet', 'nato')
            ->where('fireteams.company', ['id' => $this->company->id, 'designation' => 'A/1-7', 'show' => null, 'order' => null, 'slAslLead' => false])
            ->where('fireteams.can', ['edit' => true, 'editPolicy' => false]));
});

it('lets the company command change both, and shows a staff grant holder the controls read-only', function () {
    $co = rollcallCommander($this->company, 196);
    $granted = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $granted->id, 'unit_id' => $this->company->id]);

    $this->actingAs($co)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page->where('fireteams.can', ['edit' => true, 'editPolicy' => true]));
    $this->actingAs($granted)->get("/rollcall/{$this->section->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('fireteams.can', ['edit' => false, 'editPolicy' => false]));
});

it('shows the section\'s choices unless the company\'s policy overrides them', function () {
    $repo = app(FireteamRepository::class);
    $repo->updateSetting($this->section->id, ['show_fireteams' => true, 'fireteam_order' => FireteamOrder::ByFireteam]);

    $this->actingAs($this->slUser)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page->where('fireteams.show', true)->where('fireteams.order', 'by_fireteam'));

    $repo->updateSetting($this->company->id, ['fireteam_order' => FireteamOrder::LeadersFirst]);

    $this->actingAs($this->slUser)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page
            ->where('fireteams.show', true)
            ->where('fireteams.order', 'leaders_first')
            ->where('fireteams.section', ['show' => true, 'order' => 'by_fireteam'])
            ->where('fireteams.company.order', 'leaders_first'));

    $repo->updateSetting($this->company->id, ['show_fireteams' => false]);

    $this->actingAs($this->slUser)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page->where('fireteams.show', false));
});

it('marks an FTL and fills their fireteam, answering the section\'s fireteams as they now stand', function () {
    $this->actingAs($this->slUser)->postJson("/rollcall/{$this->section->id}/fireteams", ['trooper_id' => $this->ftl->id, 'role' => 'ftl'])
        ->assertOk()
        ->assertJsonPath('fireteams.can.edit', true);

    $response = $this->actingAs($this->slUser)->postJson("/rollcall/{$this->section->id}/fireteams", [
        'trooper_id' => $this->member->id, 'role' => 'member', 'leader_id' => $this->ftl->id,
    ])->assertOk();

    expect(collect($response->json('troopers'))->keyBy('id')[$this->member->id])->toBe(['id' => $this->member->id, 'role' => null, 'leadsFireteam' => false, 'fireteamLeaderId' => $this->ftl->id])
        ->and(collect($response->json('troopers'))->keyBy('id')[$this->ftl->id]['role'])->toBe('ftl');

    $this->actingAs($this->slUser)->postJson("/rollcall/{$this->section->id}/fireteams", ['trooper_id' => $this->ftl->id, 'role' => null])
        ->assertOk()
        ->assertJsonPath('troopers', fn ($rows) => collect($rows)->every(fn ($row) => $row['role'] !== 'ftl' && $row['fireteamLeaderId'] === null));

    expect(AuditLogEntry::query()->pluck('action')->all())->toBe(['fireteam.leader_set', 'fireteam.member_assigned', 'fireteam.leader_unset']);
});

it('answers a broken rule with 422 and its reason, and a malformed request with 422', function () {
    $this->actingAs($this->slUser)->postJson("/rollcall/{$this->section->id}/fireteams", ['trooper_id' => $this->member->id, 'role' => 'member', 'leader_id' => $this->ftl->id])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'is not a fireteam leader'));
    $this->actingAs($this->slUser)->postJson("/rollcall/{$this->section->id}/fireteams", ['trooper_id' => $this->ftl->id, 'role' => 'captain'])->assertStatus(422);
    $this->actingAs($this->slUser)->postJson("/rollcall/{$this->section->id}/fireteams", ['role' => 'ftl'])->assertStatus(422);
    $this->actingAs($this->slUser)->postJson("/rollcall/{$this->section->id}/fireteams/options", [])->assertStatus(422);
    $this->actingAs($this->slUser)->postJson("/rollcall/{$this->section->id}/fireteams/policy", ['show' => false])->assertStatus(422);

    expect(AuditLogEntry::query()->count())->toBe(0);
});

it('refuses a staff grant holder, an unknown section and anyone outside the section\'s command with 403', function () {
    $granted = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $granted->id, 'unit_id' => $this->section->id]);

    $this->actingAs($granted)->postJson("/rollcall/{$this->section->id}/fireteams", ['trooper_id' => $this->ftl->id, 'role' => 'ftl'])->assertForbidden();
    $this->actingAs($granted)->postJson("/rollcall/{$this->section->id}/fireteams/options", ['show' => true])->assertForbidden();
    $this->actingAs($this->slUser)->postJson("/rollcall/{$this->section->id}/fireteams/policy", ['show' => false, 'order' => null, 'sl_asl_lead' => false])->assertForbidden();
    $this->actingAs($this->slUser)->postJson('/rollcall/999999/fireteams', ['trooper_id' => $this->ftl->id, 'role' => 'ftl'])->assertForbidden();
    $this->actingAs($this->slUser)->postJson('/rollcall/999999/fireteams/policy', ['show' => false, 'order' => null, 'sl_asl_lead' => false])->assertForbidden();

    expect(FireteamAssignment::query()->count())->toBe(0)->and(AuditLogEntry::query()->count())->toBe(0);
});

it('answers the policy for a unit above any company with the same 403 as a missing id, unless the actor commands it (AD-8)', function () {
    $battalion = app(UnitRepository::class)->create(UnitType::Battalion, '1st Battalion', '1-7', null);
    $body = ['show' => false, 'order' => null, 'sl_asl_lead' => false];

    $missing = $this->actingAs($this->slUser)->postJson('/rollcall/999999/fireteams/policy', $body)->assertForbidden();
    $this->actingAs($this->slUser)->postJson("/rollcall/{$battalion->id}/fireteams/policy", $body)
        ->assertForbidden()
        ->assertJsonPath('message', fn (string $message) => $message === str_replace('999999', (string) $battalion->id, $missing->json('message')));

    $this->actingAs(rollcallCommander($battalion, 190))->postJson("/rollcall/{$battalion->id}/fireteams/policy", $body)
        ->assertStatus(422)
        ->assertJsonPath('message', 'This section does not sit under a company, so there is no company policy to set.');

    expect(AuditLogEntry::query()->count())->toBe(0);
});

it('sets the section\'s options and the company\'s policy, and the answer shows the policy winning', function () {
    $co = rollcallCommander($this->company, 196);

    $this->actingAs($this->slUser)->postJson("/rollcall/{$this->section->id}/fireteams/options", ['show' => true, 'order' => 'by_fireteam'])
        ->assertOk()
        ->assertJsonPath('fireteams.show', true)
        ->assertJsonPath('fireteams.order', 'by_fireteam');

    $this->actingAs($co)->postJson("/rollcall/{$this->section->id}/fireteams/policy", ['show' => null, 'order' => 'leaders_first', 'sl_asl_lead' => false])
        ->assertOk()
        ->assertJsonPath('fireteams.order', 'leaders_first')
        ->assertJsonPath('fireteams.section.order', 'by_fireteam')
        ->assertJsonPath('fireteams.company.order', 'leaders_first')
        ->assertJsonPath('fireteams.can.editPolicy', true);

    $this->actingAs($this->slUser)->postJson("/rollcall/{$this->section->id}/fireteams/options", ['order' => 'by_fireteam'])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'The company has set'));
    $this->actingAs($co)->postJson("/rollcall/{$this->section->id}/fireteams/policy", ['show' => null, 'order' => 'by_fireteam', 'sl_asl_lead' => false])->assertStatus(422);
    $this->actingAs($co)->postJson("/rollcall/{$this->section->id}/fireteams/policy", ['show' => true, 'order' => null])->assertStatus(422);

    $this->actingAs($co)->postJson("/rollcall/{$this->section->id}/fireteams/policy", ['show' => true, 'order' => null, 'sl_asl_lead' => true])
        ->assertOk()
        ->assertJsonPath('fireteams.show', true)
        ->assertJsonPath('fireteams.slAslLead', true)
        ->assertJsonPath('fireteams.company.slAslLead', true);
});

it('lets the SL lead a fireteam while the company allows it, and reads it as none once the company stops', function () {
    $co = rollcallCommander($this->company, 196);
    $this->actingAs($co)->postJson("/rollcall/{$this->section->id}/fireteams/policy", ['show' => null, 'order' => null, 'sl_asl_lead' => true])->assertOk();

    $this->actingAs($this->slUser)->postJson("/rollcall/{$this->section->id}/fireteams", ['trooper_id' => $this->sl->id, 'role' => 'ftl'])->assertOk();
    $answer = $this->actingAs($this->slUser)->postJson("/rollcall/{$this->section->id}/fireteams", ['trooper_id' => $this->member->id, 'role' => 'member', 'leader_id' => $this->sl->id])->assertOk();

    expect(collect($answer->json('troopers'))->keyBy('id')[$this->sl->id])->toBe(['id' => $this->sl->id, 'role' => 'sl', 'leadsFireteam' => true, 'fireteamLeaderId' => null])
        ->and(collect($answer->json('troopers'))->keyBy('id')[$this->member->id]['fireteamLeaderId'])->toBe($this->sl->id);

    $this->actingAs($co)->postJson("/rollcall/{$this->section->id}/fireteams/policy", ['show' => null, 'order' => null, 'sl_asl_lead' => false])->assertOk();

    $this->actingAs($this->slUser)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => expect(rolesOn($page))->toMatchArray([
            $this->sl->id => ['role' => 'sl', 'leads' => false, 'fireteamLeaderId' => null],
            $this->member->id => ['role' => null, 'leads' => false, 'fireteamLeaderId' => null],
        ]) && true);
    expect(FireteamAssignment::query()->count())->toBe(2);
});

it('names the fireteams of B/ACD, Baker Company, in the WW2 alphabet', function () {
    $units = app(UnitRepository::class);
    $baker = $units->create(UnitType::Company, 'Baker Company', 'B/ACD', null);
    $section = $units->create(UnitType::Section, '1st Section', '1/1/B/ACD', $units->create(UnitType::Platoon, '1st Platoon', '1/B/ACD', $baker->id)->id);

    $this->actingAs(User::factory()->admin()->create())->get("/rollcall/{$section->id}")
        ->assertInertia(fn ($page) => $page->where('fireteams.alphabet', 'ww2'));
});

it('refuses every fireteam write during a view-as preview, and the page offers none (1.13)', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section->id]);

    $this->actingAs($admin)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page->where('fireteams.can', ['edit' => false, 'editPolicy' => false]));
    $this->actingAs($admin)->postJson("/rollcall/{$this->section->id}/fireteams", ['trooper_id' => $this->ftl->id, 'role' => 'ftl'])->assertForbidden();
    $this->actingAs($admin)->postJson("/rollcall/{$this->section->id}/fireteams/options", ['show' => true])->assertForbidden();
    $this->actingAs($admin)->postJson("/rollcall/{$this->section->id}/fireteams/policy", ['show' => false, 'order' => null, 'sl_asl_lead' => false])->assertForbidden();

    // Starting the preview is itself audited; nothing else is.
    expect(FireteamAssignment::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->pluck('action')->all())->toBe(['view_as.started']);
});

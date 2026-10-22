<?php

use App\Commands\Fireteams\FireteamForbiddenException;
use App\Commands\Fireteams\InvalidFireteamException;
use App\Commands\Fireteams\SetCompanyFireteamPolicy;
use App\Commands\Fireteams\SetCompanyFireteamPolicyHandler;
use App\Commands\Fireteams\SetFireteamRole;
use App\Commands\Fireteams\SetFireteamRoleHandler;
use App\Commands\Fireteams\SetSectionFireteamOptions;
use App\Commands\Fireteams\SetSectionFireteamOptionsHandler;
use App\Models\AuditLogEntry;
use App\Models\FireteamAssignment;
use App\Models\FireteamOrder;
use App\Models\FireteamRole;
use App\Models\FireteamSetting;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\FireteamRepository;
use App\Repositories\UnitRepository;
use Illuminate\Auth\Access\AuthorizationException;

// A section's unofficial fireteams and how its rollcall post shows them: audited writes (AD-16) that only the leaders
// who command the unit may make; a staff grant reads, it does not write.
// Tree: A/1-7 (company) ─ 1/A/1-7 (platoon) ─┬─ 1/1/A/1-7 (section)
//                                             └─ 2/1/A/1-7 (sibling)

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->sibling = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->platoon->id);

    $this->sl = fireteamCommander($this->section, 201, ['billet' => 'Section Leader 1/1/A/1-7']);
    $this->ftl = fireteamPlaced($this->section, ['username' => 'Adams.P']);
    $this->member = fireteamPlaced($this->section, ['username' => 'Baker.Q']);
});

/** A user whose synced billet commands `$unit`; their trooper is placed in it when it is a section. */
function fireteamCommander(Unit $unit, int $positionId, array $trooper = []): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $positionId]);
    $row = Trooper::factory()->create(['cav_position_id' => $positionId, ...$trooper]);

    if ($unit->type === UnitType::Section) {
        $row->forceFill(['primary_section_id' => $unit->id])->save();
    }

    return User::factory()->create(['cav_id' => $row->cav_id]);
}

function fireteamPlaced(Unit $section, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

function setRole(User $actor, int $sectionId, int $trooperId, ?FireteamRole $role, ?int $leaderId = null): ?FireteamAssignment
{
    return app(SetFireteamRoleHandler::class)->handle($actor, new SetFireteamRole($sectionId, $trooperId, $role, $leaderId));
}

function setOptions(User $actor, int $sectionId, ?bool $show = null, ?FireteamOrder $order = null): FireteamSetting
{
    return app(SetSectionFireteamOptionsHandler::class)->handle($actor, new SetSectionFireteamOptions($sectionId, $show, $order));
}

function setPolicy(User $actor, int $companyId, ?bool $show, ?FireteamOrder $order, bool $slAslLead = false): FireteamSetting
{
    return app(SetCompanyFireteamPolicyHandler::class)->handle($actor, new SetCompanyFireteamPolicy($companyId, $show, $order, $slAslLead));
}

describe('setting a trooper\'s fireteam role', function () {
    it('marks an FTL and puts a member in their fireteam, each as one audit entry', function () {
        $leader = setRole($this->sl, $this->section->id, $this->ftl->id, FireteamRole::Leader);
        $member = setRole($this->sl, $this->section->id, $this->member->id, FireteamRole::Member, $this->ftl->id);

        expect($leader->role)->toBe(FireteamRole::Leader)
            ->and($member->leader_trooper_id)->toBe($this->ftl->id)
            ->and(AuditLogEntry::query()->orderBy('id')->pluck('action')->all())->toBe(['fireteam.leader_set', 'fireteam.member_assigned'])
            ->and((int) AuditLogEntry::query()->latest('id')->first()->target_id)->toBe($member->id)
            ->and(AuditLogEntry::query()->first()->actor_id)->toBe($this->sl->id);
    });

    it('unmarking an FTL takes their members out of the fireteam too', function () {
        setRole($this->sl, $this->section->id, $this->ftl->id, FireteamRole::Leader);
        setRole($this->sl, $this->section->id, $this->member->id, FireteamRole::Member, $this->ftl->id);

        $removed = setRole($this->sl, $this->section->id, $this->ftl->id, null);

        expect($removed->trooper_id)->toBe($this->ftl->id)
            ->and(FireteamAssignment::query()->count())->toBe(0)
            ->and(AuditLogEntry::query()->latest('id')->first()->action)->toBe('fireteam.leader_unset');
    });

    it('takes a member out of their fireteam, and logs nothing when there is nothing to take out', function () {
        setRole($this->sl, $this->section->id, $this->ftl->id, FireteamRole::Leader);
        setRole($this->sl, $this->section->id, $this->member->id, FireteamRole::Member, $this->ftl->id);

        setRole($this->sl, $this->section->id, $this->member->id, null);
        $entries = AuditLogEntry::query()->count();

        expect(AuditLogEntry::query()->latest('id')->first()->action)->toBe('fireteam.member_unassigned')
            ->and(setRole($this->sl, $this->section->id, $this->member->id, null))->toBeNull()
            ->and(AuditLogEntry::query()->count())->toBe($entries)
            ->and(FireteamAssignment::query()->sole()->trooper_id)->toBe($this->ftl->id);
    });

    it('lets the ASL, the platoon leader, the company command and an Admin set fireteams', function () {
        $asl = fireteamCommander($this->section, 202, ['billet' => 'Assistant Section Leader 1/1/A/1-7']);
        $pl = fireteamCommander($this->platoon, 199);
        $co = fireteamCommander($this->company, 196);

        foreach ([$asl, $pl, $co, User::factory()->admin()->create()] as $actor) {
            setRole($actor, $this->section->id, $this->ftl->id, FireteamRole::Leader);
            setRole($actor, $this->section->id, $this->ftl->id, null);
        }

        expect(AuditLogEntry::query()->count())->toBe(8);
    });

    it('refuses a staff grant holder, a sibling section\'s leader and anyone else, writing nothing (AD-8)', function () {
        $granted = User::factory()->serving()->create();
        PortalRoleGrant::factory()->create(['user_id' => $granted->id, 'unit_id' => $this->section->id]);
        $siblingSl = fireteamCommander($this->sibling, 301);

        foreach ([$granted, $siblingSl, User::factory()->serving()->create()] as $actor) {
            expect(fn () => setRole($actor, $this->section->id, $this->ftl->id, FireteamRole::Leader))
                ->toThrow(FireteamForbiddenException::class);
        }

        expect(FireteamAssignment::query()->count())->toBe(0)
            ->and(AuditLogEntry::query()->count())->toBe(0)
            ->and(new FireteamForbiddenException(1))->toBeInstanceOf(AuthorizationException::class);
    });

    it('refuses an id that does not exist as it refuses one outside command, and a unit that is not a section', function () {
        expect(fn () => setRole($this->sl, 999999, $this->ftl->id, FireteamRole::Leader))->toThrow(FireteamForbiddenException::class)
            ->and(fn () => setRole(User::factory()->admin()->create(), $this->platoon->id, $this->ftl->id, FireteamRole::Leader))
            ->toThrow(InvalidFireteamException::class, 'is not a section');
    });

    it('refuses a trooper who is not placed in the section', function () {
        $elsewhere = fireteamPlaced($this->sibling);

        expect(fn () => setRole($this->sl, $this->section->id, $elsewhere->id, FireteamRole::Leader))
            ->toThrow(InvalidFireteamException::class, 'is not placed in this section');
    });

    it('lets the SL and ASL lead a fireteam only while the company allows it, and never be a member', function () {
        $asl = fireteamPlaced($this->section, ['billet' => 'Assistant Section Leader 1/1/A/1-7']);
        $slTrooper = Trooper::query()->where('cav_id', $this->sl->cav_id)->sole();
        $admin = User::factory()->admin()->create();
        setPolicy($admin, $this->company->id, show: null, order: null, slAslLead: true);

        setRole($this->sl, $this->section->id, $slTrooper->id, FireteamRole::Leader);
        setRole($this->sl, $this->section->id, $asl->id, FireteamRole::Leader);
        $member = setRole($this->sl, $this->section->id, $this->member->id, FireteamRole::Member, $slTrooper->id);

        expect($member->leader_trooper_id)->toBe($slTrooper->id)
            ->and(fn () => setRole($this->sl, $this->section->id, $asl->id, FireteamRole::Member, $slTrooper->id))->toThrow(InvalidFireteamException::class, "section's ASL");

        // Turned off again: the rows stay, but no new SL or ASL fireteam, and nobody joins one.
        setPolicy($admin, $this->company->id, show: null, order: null, slAslLead: false);

        expect(fn () => setRole($this->sl, $this->section->id, $slTrooper->id, FireteamRole::Leader))->toThrow(InvalidFireteamException::class, "section's SL")
            ->and(fn () => setRole($this->sl, $this->section->id, $this->ftl->id, FireteamRole::Member, $asl->id))->toThrow(InvalidFireteamException::class, 'is not a fireteam leader')
            ->and(FireteamAssignment::query()->count())->toBe(3);

        // Taking an SL out of their old fireteam still works, and takes their members out too.
        setRole($this->sl, $this->section->id, $slTrooper->id, null);

        expect(FireteamAssignment::query()->pluck('trooper_id')->all())->toBe([$asl->id]);
    });

    it('refuses to make the SL or ASL an FTL or a member while the company has not allowed it', function () {
        $asl = fireteamPlaced($this->section, ['billet' => 'Assistant Section Leader 1/1/A/1-7']);
        $slTrooper = Trooper::query()->where('cav_id', $this->sl->cav_id)->sole();
        setRole($this->sl, $this->section->id, $this->ftl->id, FireteamRole::Leader);

        expect(fn () => setRole($this->sl, $this->section->id, $slTrooper->id, FireteamRole::Leader))->toThrow(InvalidFireteamException::class, "section's SL")
            ->and(fn () => setRole($this->sl, $this->section->id, $asl->id, FireteamRole::Member, $this->ftl->id))->toThrow(InvalidFireteamException::class, "section's ASL")
            ->and(fn () => setRole($this->sl, $this->section->id, $this->member->id, FireteamRole::Member, $slTrooper->id))->toThrow(InvalidFireteamException::class, 'is not a fireteam leader');
    });

    it('refuses a member whose leader is not an FTL of this section, is missing, or is the member', function () {
        $elsewhere = fireteamPlaced($this->sibling);
        app(FireteamRepository::class)->makeLeader($this->sibling->id, $elsewhere->id);

        expect(fn () => setRole($this->sl, $this->section->id, $this->member->id, FireteamRole::Member, $this->ftl->id))->toThrow(InvalidFireteamException::class, 'is not a fireteam leader')
            ->and(fn () => setRole($this->sl, $this->section->id, $this->member->id, FireteamRole::Member, $elsewhere->id))->toThrow(InvalidFireteamException::class, 'is not a fireteam leader')
            ->and(fn () => setRole($this->sl, $this->section->id, $this->member->id, FireteamRole::Member))->toThrow(InvalidFireteamException::class, 'Choose the fireteam leader')
            ->and(fn () => setRole($this->sl, $this->section->id, $this->member->id, FireteamRole::Member, $this->member->id))->toThrow(InvalidFireteamException::class, 'their own fireteam');

        expect(FireteamAssignment::query()->where('section_id', $this->section->id)->count())->toBe(0);
    });

    it('refuses to put an FTL in another fireteam until they are unmarked, but lets a member become an FTL', function () {
        setRole($this->sl, $this->section->id, $this->ftl->id, FireteamRole::Leader);
        setRole($this->sl, $this->section->id, $this->member->id, FireteamRole::Leader);

        expect(fn () => setRole($this->sl, $this->section->id, $this->member->id, FireteamRole::Member, $this->ftl->id))
            ->toThrow(InvalidFireteamException::class, 'leads a fireteam');

        setRole($this->sl, $this->section->id, $this->member->id, null);
        setRole($this->sl, $this->section->id, $this->member->id, FireteamRole::Member, $this->ftl->id);
        setRole($this->sl, $this->section->id, $this->member->id, FireteamRole::Leader);

        expect(app(FireteamRepository::class)->assignmentsIn($this->section->id)[$this->member->id]->role)->toBe(FireteamRole::Leader);
    });
});

describe('a section\'s fireteam display', function () {
    it('records the section\'s choices, only the ones given, as one audit entry each', function () {
        setOptions($this->sl, $this->section->id, show: true);
        $setting = setOptions($this->sl, $this->section->id, order: FireteamOrder::ByFireteam);

        expect($setting->show_fireteams)->toBeTrue()
            ->and($setting->fireteam_order)->toBe(FireteamOrder::ByFireteam)
            ->and(AuditLogEntry::query()->pluck('action')->all())->toBe(['fireteam.options_set', 'fireteam.options_set']);
    });

    it('refuses a staff grant holder and a sibling section\'s leader', function () {
        $granted = User::factory()->serving()->create();
        PortalRoleGrant::factory()->create(['user_id' => $granted->id, 'unit_id' => $this->section->id]);

        foreach ([$granted, fireteamCommander($this->sibling, 301)] as $actor) {
            expect(fn () => setOptions($actor, $this->section->id, show: true))->toThrow(FireteamForbiddenException::class);
        }

        expect(FireteamSetting::query()->count())->toBe(0);
    });

    it('refuses a choice the company enforces, and allows the enforced value and the choices it leaves open', function () {
        setPolicy(User::factory()->admin()->create(), $this->company->id, show: null, order: FireteamOrder::LeadersFirst);

        expect(fn () => setOptions($this->sl, $this->section->id, order: FireteamOrder::ByFireteam))
            ->toThrow(InvalidFireteamException::class, 'The company has set where fireteam leaders are listed');

        setOptions($this->sl, $this->section->id, show: true, order: FireteamOrder::LeadersFirst);
        setPolicy(User::factory()->admin()->create(), $this->company->id, show: false, order: null);

        expect(fn () => setOptions($this->sl, $this->section->id, show: true))
            ->toThrow(InvalidFireteamException::class, 'The company has set whether fireteam leaders are shown');

        setOptions($this->sl, $this->section->id, order: FireteamOrder::ByFireteam);

        expect(app(FireteamRepository::class)->settingFor($this->section->id)->fireteam_order)->toBe(FireteamOrder::ByFireteam);
    });

    it('refuses a unit that is not a section', function () {
        expect(fn () => setOptions(User::factory()->admin()->create(), $this->platoon->id, show: true))
            ->toThrow(InvalidFireteamException::class, 'is not a section');
    });
});

describe('a company\'s fireteam policy', function () {
    it('records whether the SL and ASL may lead a fireteam, off unless given', function () {
        $admin = User::factory()->admin()->create();

        expect(setPolicy($admin, $this->company->id, show: null, order: null, slAslLead: true)->sl_asl_lead_fireteams)->toBeTrue()
            ->and(setPolicy($admin, $this->company->id, show: null, order: null)->sl_asl_lead_fireteams)->toBeFalse();
    });

    it('lets the company command, the battalion above it and an Admin set it, as one audit entry', function () {
        $battalion = app(UnitRepository::class)->create(UnitType::Battalion, '1st Battalion', '1-7', null);
        app(UnitRepository::class)->move($this->company, $battalion->id);
        $bn = fireteamCommander($battalion, 178);
        $co = fireteamCommander($this->company->fresh(), 196);

        setPolicy($co, $this->company->id, show: false, order: null);
        setPolicy($bn, $this->company->id, show: null, order: FireteamOrder::LeadersFirst);
        $policy = setPolicy(User::factory()->admin()->create(), $this->company->id, show: false, order: FireteamOrder::LeadersFirst);

        expect($policy->show_fireteams)->toBeFalse()
            ->and($policy->fireteam_order)->toBe(FireteamOrder::LeadersFirst)
            ->and(AuditLogEntry::query()->where('action', 'fireteam.policy_set')->count())->toBe(3);
    });

    it('is not the section or platoon leader\'s, nor a staff grant holder\'s, to set', function () {
        $granted = User::factory()->serving()->create();
        PortalRoleGrant::factory()->create(['user_id' => $granted->id, 'unit_id' => $this->company->id]);

        foreach ([$this->sl, fireteamCommander($this->platoon, 199), $granted] as $actor) {
            expect(fn () => setPolicy($actor, $this->company->id, show: false, order: null))->toThrow(FireteamForbiddenException::class);
        }

        expect(FireteamSetting::query()->count())->toBe(0);
    });

    it('can force FTLs shown or hidden, but never the by-fireteam order', function () {
        $admin = User::factory()->admin()->create();

        expect(setPolicy($admin, $this->company->id, show: true, order: null)->show_fireteams)->toBeTrue()
            ->and(fn () => setPolicy($admin, $this->company->id, show: null, order: FireteamOrder::ByFireteam))->toThrow(InvalidFireteamException::class)
            ->and(fn () => setPolicy($admin, $this->section->id, show: false, order: null))->toThrow(InvalidFireteamException::class, 'is not a company');
    });

    it('leaves the sections\' own choices alone, so lifting the policy brings them back', function () {
        $admin = User::factory()->admin()->create();
        setOptions($this->sl, $this->section->id, show: true, order: FireteamOrder::ByFireteam);
        setPolicy($admin, $this->company->id, show: false, order: FireteamOrder::LeadersFirst);

        $repo = app(FireteamRepository::class);
        expect(FireteamSetting::effective($repo->settingFor($this->company->id), $repo->settingFor($this->section->id)))
            ->toBe(['show' => false, 'order' => FireteamOrder::LeadersFirst, 'slAslLead' => false]);

        setPolicy($admin, $this->company->id, show: null, order: null);

        expect(FireteamSetting::effective($repo->settingFor($this->company->id), $repo->settingFor($this->section->id)))
            ->toBe(['show' => true, 'order' => FireteamOrder::ByFireteam, 'slAslLead' => false]);
    });
});

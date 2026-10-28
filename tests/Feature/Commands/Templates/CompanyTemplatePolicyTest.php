<?php

use App\Authorization\ScopeResolver;
use App\Commands\Fireteams\SetCompanyFireteamPolicy;
use App\Commands\Fireteams\SetCompanyFireteamPolicyHandler;
use App\Commands\Templates\ActivateTemplateVersion;
use App\Commands\Templates\AuthorTemplateVersion;
use App\Commands\Templates\CreateTemplate;
use App\Commands\Templates\InvalidTemplateException;
use App\Commands\Templates\PickSectionTemplate;
use App\Commands\Templates\SetCompanyTemplate;
use App\Commands\Templates\SetCompanyTemplatePolicy;
use App\Commands\Templates\SwitchTemplate;
use App\Commands\Templates\TemplateForbiddenException;
use App\Models\ArtifactType;
use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitRepository;

// #376 / AD-8, AD-16, AD-17: battalion command switches off a company's right to set its own rollcall templates. With
// it off, the battalion command and Admins manage them, exactly as the company could; everything else stays with the
// company. Tree: 7CAV ─┬─ 1-7 ─┬─ A/1-7 ─ 1/A/1-7 ── 1/1/A/1-7
//                      │       └─ B/1-7
//                      └─ 2-7 ─── A/2-7

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->otherBattalion = $units->create(UnitType::Battalion, '2nd Battalion', '2-7', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->sibling = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);

    $this->admin = User::factory()->admin()->create();
    $this->co = policyCommander($this->company, 401);
    $this->firstSergeant = policyCommander($this->company, 402);
    $this->bnCo = policyCommander($this->battalion, 403);
    $this->bnSgm = policyCommander($this->battalion, 404);
    $this->rgt = policyCommander($this->regiment, 405);
    $this->otherBn = policyCommander($this->otherBattalion, 406);
    $this->sl = policyCommander($this->section, 407);

    $this->repo = app(TemplateVersionRepository::class);
    $this->type = ArtifactType::RollcallBbcode;
    $this->standard = app(CreateTemplate::class)->handle($this->co, $this->company, $this->type, 'Standard', 'standard', 'why');
    $this->policy = app(SetCompanyTemplatePolicy::class);
});

function policyCommander(Unit $unit, int $positionId): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $positionId]);
    $trooper = Trooper::factory()->create(['cav_position_id' => $positionId]);

    return User::factory()->create(['cav_id' => $trooper->cav_id]);
}

it('is on by default: the company sets its own templates', function () {
    expect($this->repo->companyEditsTemplates($this->company->id))->toBeTrue()
        ->and($this->standard->company_id)->toBe($this->company->id);
});

it('lets battalion command (CO and SGM), the regiment command and an Admin switch it, audited, and a repeat is not logged', function (string $who) {
    $policy = $this->policy->handle($this->{$who}, $this->company, false);
    $this->policy->handle($this->{$who}, $this->company, false);
    $entry = AuditLogEntry::query()->where('action', 'template_policy.set')->sole();

    expect($policy->company_edits_templates)->toBeFalse()
        ->and($this->repo->companyEditsTemplates($this->company->id))->toBeFalse()
        ->and($this->repo->companyEditsTemplates($this->sibling->id))->toBeTrue()
        ->and($entry->actor_id)->toBe($this->{$who}->id)
        ->and($entry->target_type)->toBe('CompanyTemplatePolicy');

    $this->policy->handle($this->{$who}, $this->company, true);

    expect($this->repo->companyEditsTemplates($this->company->id))->toBeTrue();
})->with(['bnCo', 'bnSgm', 'rgt', 'admin']);

it("refuses the company's own command, another battalion's command, a section leader and a staff grant", function (string $who) {
    if ($who === 'grant') {
        $this->grant = User::factory()->serving()->create();
        PortalRoleGrant::factory()->create(['user_id' => $this->grant->id, 'unit_id' => $this->battalion->id]);
    }

    expect(fn () => $this->policy->handle($this->{$who}, $this->company, false))->toThrow(TemplateForbiddenException::class);
    expect($this->repo->companyEditsTemplates($this->company->id))->toBeTrue()
        ->and(AuditLogEntry::query()->where('action', 'template_policy.set')->count())->toBe(0);
})->with(['co', 'firstSergeant', 'otherBn', 'sl', 'grant']);

it('refuses a unit that is not a company', function () {
    expect(fn () => $this->policy->handle($this->admin, $this->platoon, false))->toThrow(InvalidTemplateException::class, 'not a company');
});

describe('with the switch off', function () {
    beforeEach(function () {
        $this->policy->handle($this->bnCo, $this->company, false);
        $this->training = $this->repo->createTemplate($this->company->id, $this->type, 'Training', 'training', $this->admin->id);
    });

    it("refuses the company's command every template write, saying the battalion turned it off, and writes nothing", function (string $who) {
        $actor = $this->{$who};
        $before = AuditLogEntry::query()->count();

        foreach ([
            fn () => app(CreateTemplate::class)->handle($actor, $this->company, $this->type, 'Mine', 'body', 'why'),
            fn () => app(AuthorTemplateVersion::class)->handle($actor, $this->standard, 'changed', 'why'),
            fn () => app(ActivateTemplateVersion::class)->handle($actor, $this->standard->activeVersion, 'why'),
            fn () => app(SwitchTemplate::class)->handle($actor, $this->training, false),
            fn () => app(SetCompanyTemplate::class)->handle($actor, $this->training),
        ] as $write) {
            expect($write)->toThrow(TemplateForbiddenException::class, 'Battalion command has turned off');
        }

        expect(AuditLogEntry::query()->count())->toBe($before);
    })->with(['co', 'firstSergeant']);

    it('lets battalion command and an Admin manage the company templates exactly as the company could', function (string $who) {
        $actor = $this->{$who};

        $created = app(CreateTemplate::class)->handle($actor, $this->company, $this->type, "By {$who}", 'body', 'why');
        $edited = app(AuthorTemplateVersion::class)->handle($actor, $created, 'body two', 'why');
        app(SwitchTemplate::class)->handle($actor, $this->training, false);
        app(SetCompanyTemplate::class)->handle($actor, $created);
        app(ActivateTemplateVersion::class)->handle($actor, $this->repo->historyOf($created)->firstWhere('version', 1), 'roll back');

        expect($edited->version)->toBe(2)
            ->and($this->repo->choiceFor($this->company->id, $this->type)->template_id)->toBe($created->id)
            ->and($this->repo->findTemplate($created->id)->activeVersion->version)->toBe(1)
            ->and($this->training->refresh()->enabled)->toBeFalse();
    })->with(['bnCo', 'bnSgm', 'admin']);

    it('leaves the rest of the rollcall with the company: write scope, fireteam policy and the section pick', function () {
        $scope = app(ScopeResolver::class);

        expect($scope->writeScope($this->co)->contains($this->section->id))->toBeTrue();

        app(SetCompanyFireteamPolicyHandler::class)->handle($this->co, new SetCompanyFireteamPolicy($this->company->id, true, null));
        app(PickSectionTemplate::class)->handle($this->sl, $this->section->id, $this->training->id);

        expect($this->repo->choiceFor($this->section->id, $this->type)->template_id)->toBe($this->training->id);
    });

    it('leaves a sibling company under the same battalion editing its own', function () {
        $siblingCo = policyCommander($this->sibling, 408);

        expect(app(CreateTemplate::class)->handle($siblingCo, $this->sibling, $this->type, 'Own', 'body', 'why')->company_id)->toBe($this->sibling->id);
    });
});

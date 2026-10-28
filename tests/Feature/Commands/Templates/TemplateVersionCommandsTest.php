<?php

use App\Commands\Templates\ActivateTemplateVersion;
use App\Commands\Templates\AuthorTemplateVersion;
use App\Commands\Templates\CreateTemplate;
use App\Commands\Templates\InvalidTemplateException;
use App\Commands\Templates\PickSectionTemplate;
use App\Commands\Templates\SetCompanyTemplate;
use App\Commands\Templates\SwitchTemplate;
use App\Commands\Templates\TemplateForbiddenException;
use App\Models\ArtifactType;
use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitTemplateChoice;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TemplateUnchangedException;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitRepository;
use App\Rollcall\TemplateSyntax;
use App\Support\Audit\ReasonRequiredException;

// Story 3.5, #375 / AD-8, AD-16, AD-17: a company's command manages its own templates (an edit is a new audited
// version with a reason, a rollback only moves the pointer), switches them on or off for its sections and picks the
// company default; a section leader picks among the switched-on ones; only an Admin edits the regiment default.
// Tree: 7CAV ─ 1-7 ─┬─ A/1-7 ─ 1/A/1-7 ─┬─ 1/1/A/1-7
//                   │                  └─ 2/1/A/1-7
//                   └─ B/1-7 ─ 1/B/1-7 ── 1/1/B/1-7

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->sibling = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->platoon->id);
    $this->other = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', $this->battalion->id);
    $otherPlatoon = $units->create(UnitType::Platoon, '1st Platoon', '1/B/1-7', $this->other->id);
    $this->otherSection = $units->create(UnitType::Section, '1st Section', '1/1/B/1-7', $otherPlatoon->id);

    $this->admin = User::factory()->admin()->create();
    $this->co = templateCommander($this->company, 301);
    $this->xo = templateCommander($this->company, 302);
    $this->bn = templateCommander($this->battalion, 303);
    $this->otherCo = templateCommander($this->other, 304);
    $this->pl = templateCommander($this->platoon, 305);
    $this->sl = templateCommander($this->section, 306);

    $this->author = app(AuthorTemplateVersion::class);
    $this->activate = app(ActivateTemplateVersion::class);
    $this->create = app(CreateTemplate::class);
    $this->switch = app(SwitchTemplate::class);
    $this->setActive = app(SetCompanyTemplate::class);
    $this->pick = app(PickSectionTemplate::class);
    $this->repo = app(TemplateVersionRepository::class);
    $this->type = ArtifactType::RollcallBbcode;
    $this->default = $this->repo->regimentDefault($this->type);
    $this->audited = fn () => AuditLogEntry::query()->count();
});

/** A user whose synced billet commands `$unit`. */
function templateCommander(Unit $unit, int $positionId): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $positionId]);
    $trooper = Trooper::factory()->create(['cav_position_id' => $positionId]);

    return User::factory()->create(['cav_id' => $trooper->cav_id]);
}

describe('the regiment default', function () {
    it('exists from the migration, seeded from the starter, with no company and no author', function () {
        expect($this->default)->not->toBeNull()
            ->and($this->default->company_id)->toBeNull()
            ->and($this->default->name)->toBe('Regiment default')
            ->and($this->default->activeVersion->body)->toBe(TemplateSyntax::STARTER)
            ->and($this->default->activeVersion->created_by)->toBeNull();
    });

    it('is edited by an Admin, as a new audited version', function () {
        $version = $this->author->handle($this->admin, $this->default, 'regiment v2', 'Tidy the header');

        expect($version->version)->toBe(2)
            ->and($this->repo->regimentDefault($this->type)->activeVersion->id)->toBe($version->id)
            ->and(AuditLogEntry::query()->sole()->action)->toBe('template_version.authored');
    });

    it('is refused to everyone else, a company or battalion commander included, and writes nothing', function (string $who) {
        expect(fn () => $this->author->handle($this->{$who}, $this->default, 'mine now', 'why'))->toThrow(TemplateForbiddenException::class, 'Only an Admin');

        $old = $this->default->activeVersion;
        expect(fn () => $this->activate->handle($this->{$who}, $old, 'why'))->toThrow(TemplateForbiddenException::class);
        expect(($this->audited)())->toBe(0)->and(TemplateVersion::query()->count())->toBe(1);
    })->with(['co', 'bn', 'sl']);

    it('cannot be switched off or made a company default', function () {
        expect(fn () => $this->switch->handle($this->admin, $this->default, false))->toThrow(InvalidTemplateException::class, 'always available')
            ->and(fn () => $this->setActive->handle($this->admin, $this->default))->toThrow(InvalidTemplateException::class);
    });
});

describe('creating a company template', function () {
    it('lets the company command add a named template, switched on, as the company default when it has none, audited with a reason', function () {
        $template = $this->create->handle($this->co, $this->company, $this->type, 'Standard', '[B]{{unit}}[/B]', 'Our own post');
        $entry = AuditLogEntry::query()->sole();

        expect($template->company_id)->toBe($this->company->id)
            ->and($template->enabled)->toBeTrue()
            ->and($template->activeVersion->version)->toBe(1)
            ->and($this->repo->choiceFor($this->company->id, $this->type)->template_id)->toBe($template->id)
            ->and($entry->action)->toBe('template.created')
            ->and($entry->actor_id)->toBe($this->co->id)
            ->and($entry->reason)->toBe('Our own post')
            ->and($entry->target_type)->toBe('Template');
    });

    it('leaves the company default alone when the company already has one', function () {
        $first = $this->create->handle($this->co, $this->company, $this->type, 'Standard', 'one', 'why');
        $this->create->handle($this->xo, $this->company, $this->type, 'Training week', 'two', 'why');

        expect($this->repo->choiceFor($this->company->id, $this->type)->template_id)->toBe($first->id)
            ->and($this->repo->templatesOf($this->company->id, $this->type)->pluck('name')->all())->toBe(['Standard', 'Training week']);
    });

    it('lets the XO, the battalion command above and an Admin do it too', function (string $who) {
        expect($this->create->handle($this->{$who}, $this->company, $this->type, "By {$who}", 'body', 'why')->company_id)->toBe($this->company->id);
    })->with(['xo', 'bn', 'admin']);

    it("refuses another company's command, a platoon or section leader, and a staff grant, writing nothing", function (string $who) {
        if ($who === 'grant') {
            $this->grant = User::factory()->serving()->create();
            PortalRoleGrant::factory()->create(['user_id' => $this->grant->id, 'unit_id' => $this->company->id]);
        }

        expect(fn () => $this->create->handle($this->{$who}, $this->company, $this->type, 'Mine', 'body', 'why'))->toThrow(TemplateForbiddenException::class);
        expect(Template::query()->whereNotNull('company_id')->count())->toBe(0)->and(($this->audited)())->toBe(0);
    })->with(['otherCo', 'pl', 'sl', 'grant']);

    it('refuses a unit that is not a company, a blank, too long or taken name, and a bad body', function (Closure $call, string $message) {
        $this->create->handle($this->co, $this->company, $this->type, 'Standard', 'body', 'why');

        expect(fn () => $call($this))->toThrow(InvalidTemplateException::class, $message);
        expect(Template::query()->whereNotNull('company_id')->count())->toBe(1);
    })->with([
        'a platoon' => [fn ($t) => $t->create->handle($t->admin, $t->platoon, $t->type, 'X', 'body', 'why'), 'belongs to a company or a section'],
        'a blank name' => [fn ($t) => $t->create->handle($t->co, $t->company, $t->type, '   ', 'body', 'why'), 'needs a name'],
        'a long name' => [fn ($t) => $t->create->handle($t->co, $t->company, $t->type, str_repeat('n', 61), 'body', 'why'), 'at most 60'],
        'a taken name' => [fn ($t) => $t->create->handle($t->co, $t->company, $t->type, ' standard ', 'body', 'why'), 'already has'],
        'an empty body' => [fn ($t) => $t->create->handle($t->co, $t->company, $t->type, 'New', '  ', 'why'), 'needs some text'],
        'a bad body' => [fn ($t) => $t->create->handle($t->co, $t->company, $t->type, 'New', '{{colour}}', 'why'), 'Unknown name'],
    ]);

    it('requires a reason', function () {
        expect(fn () => $this->create->handle($this->co, $this->company, $this->type, 'Standard', 'body', ' '))->toThrow(ReasonRequiredException::class);
        expect(Template::query()->whereNotNull('company_id')->count())->toBe(0);
    });
});

describe('editing and rolling back', function () {
    beforeEach(function () {
        $this->template = $this->create->handle($this->co, $this->company, $this->type, 'Standard', 'first body', 'why');
    });

    it('writes an edit as a new version, leaves the earlier one unchanged and records who and why', function () {
        $second = $this->author->handle($this->co, $this->template->refresh(), 'second body', 'Add the FTL column');
        $entry = AuditLogEntry::query()->latest('id')->first();

        expect($second->version)->toBe(2)
            ->and($this->repo->findTemplate($this->template->id)->activeVersion->body)->toBe('second body')
            ->and($this->repo->historyOf($this->template)->pluck('body')->all())->toBe(['second body', 'first body'])
            ->and($entry->action)->toBe('template_version.authored')
            ->and($entry->actor_id)->toBe($this->co->id)
            ->and($entry->reason)->toBe('Add the FTL column')
            ->and($entry->target_type)->toBe('TemplateVersion');
    });

    it("refuses another company's command and the leaders beneath, and writes nothing", function (string $who) {
        expect(fn () => $this->author->handle($this->{$who}, $this->template, 'hijack', 'why'))->toThrow(TemplateForbiddenException::class);
        expect(TemplateVersion::query()->where('template_id', $this->template->id)->count())->toBe(1);
    })->with(['otherCo', 'pl', 'sl']);

    it('requires a reason, and a rejected edit leaves no version and no log entry', function (?string $reason) {
        expect(fn () => $this->author->handle($this->co, $this->template, 'body', $reason))->toThrow(ReasonRequiredException::class);

        expect(TemplateVersion::query()->where('template_id', $this->template->id)->count())->toBe(1)->and(($this->audited)())->toBe(1);
    })->with([[null], [''], ['   ']]);

    it('refuses a body that is empty, over the limit, or with unknown or unbalanced codes, against the body field', function (string $body) {
        $thrown = null;

        try {
            $this->author->handle($this->co, $this->template, $body, 'why');
        } catch (InvalidTemplateException $e) {
            $thrown = $e;
        }

        expect($thrown?->field)->toBe('body')->and(TemplateVersion::query()->where('template_id', $this->template->id)->count())->toBe(1);
    })->with(['empty' => '  ', 'too long' => str_repeat('a', AuthorTemplateVersion::MAX_BODY_LENGTH + 1), 'unknown' => '{{colour}}', 'unclosed' => '{{#troopers}}{{name}}']);

    it('accepts a body exactly at the limit, and keeps it exactly as written', function () {
        $tail = "[B]✓[/B]\r\n  [TD]✗[/TD]";
        $body = str_repeat('a', AuthorTemplateVersion::MAX_BODY_LENGTH - mb_strlen($tail)).$tail;

        $version = $this->author->handle($this->co, $this->template, $body, 'why');

        expect($this->repo->find($version->id)->body)->toBe($body);
    });

    it('treats saving the active text again as a no-op: no new version, no log entry', function () {
        $again = $this->author->handle($this->co, $this->template->refresh(), 'first body', 'again');

        expect($again->id)->toBe($this->template->active_version_id)->and(($this->audited)())->toBe(1);
    });

    it('stops a double submit that raced past the pre-check: the repository decides under its lock', function () {
        $stale = $this->template->refresh();
        $this->author->handle($this->co, $stale, 'second body', 'one');

        // The page still holds version 1 as active, so the cheap check passes; the repository refuses under its lock.
        $again = $this->author->handle($this->co, $stale, 'second body', 'two');

        expect($again->version)->toBe(2)
            ->and(TemplateVersion::query()->where('template_id', $this->template->id)->count())->toBe(2)
            ->and(AuditLogEntry::query()->where('action', 'template_version.authored')->count())->toBe(1);
    });

    it('rolls back by moving the pointer, audited with a reason, writing no new version', function () {
        $first = $this->template->activeVersion;
        $this->author->handle($this->co, $this->template->refresh(), 'second body', 'two');

        $this->activate->handle($this->xo, $first, 'The second edit broke the table');
        $entry = AuditLogEntry::query()->latest('id')->first();

        expect($this->repo->findTemplate($this->template->id)->active_version_id)->toBe($first->id)
            ->and(TemplateVersion::query()->where('template_id', $this->template->id)->count())->toBe(2)
            ->and($entry->action)->toBe('template_version.activated')
            ->and($entry->reason)->toBe('The second edit broke the table');
    });

    it('requires a reason to roll back, refuses another company, and ignores activating the active one', function () {
        $first = $this->template->activeVersion;
        $second = $this->author->handle($this->co, $this->template->refresh(), 'second body', 'two');

        expect(fn () => $this->activate->handle($this->co, $first, null))->toThrow(ReasonRequiredException::class)
            ->and(fn () => $this->activate->handle($this->otherCo, $first, 'why'))->toThrow(TemplateForbiddenException::class);

        $this->activate->handle($this->co, $second, 'again');

        expect($this->repo->findTemplate($this->template->id)->active_version_id)->toBe($second->id)->and(($this->audited)())->toBe(2);
    });

    it('refuses, in the repository, a second activation that raced past the pre-check', function () {
        $first = $this->template->activeVersion;
        $this->author->handle($this->co, $this->template->refresh(), 'second body', 'two');
        $this->repo->activate($first, $this->co->id);

        expect(fn () => $this->repo->activate($first, $this->co->id))->toThrow(TemplateUnchangedException::class);
    });
});

describe('switching templates on and off, and the company default', function () {
    beforeEach(function () {
        $this->standard = $this->create->handle($this->co, $this->company, $this->type, 'Standard', 'standard', 'why');
        $this->training = $this->create->handle($this->co, $this->company, $this->type, 'Training week', 'training', 'why');
    });

    it('switches a template off and on again, each audited, and a repeat is not logged', function () {
        $off = $this->switch->handle($this->co, $this->training, false);
        $this->switch->handle($this->co, $off, false);
        $on = $this->switch->handle($this->xo, $off, true);

        expect($on->enabled)->toBeTrue()
            ->and(AuditLogEntry::query()->whereIn('action', ['template.switched_off', 'template.switched_on'])->pluck('action')->all())->toBe(['template.switched_off', 'template.switched_on']);
    });

    it("refuses another company's command and the leaders beneath", function (string $who) {
        expect(fn () => $this->switch->handle($this->{$who}, $this->training, false))->toThrow(TemplateForbiddenException::class)
            ->and(fn () => $this->setActive->handle($this->{$who}, $this->training))->toThrow(TemplateForbiddenException::class);

        expect($this->training->refresh()->enabled)->toBeTrue()
            ->and($this->repo->choiceFor($this->company->id, $this->type)->template_id)->toBe($this->standard->id);
    })->with(['otherCo', 'pl', 'sl']);

    it('makes another template the company default, audited, and a repeat is not logged', function () {
        $this->setActive->handle($this->co, $this->training);
        $this->setActive->handle($this->co, $this->training);

        expect($this->repo->choiceFor($this->company->id, $this->type)->template_id)->toBe($this->training->id)
            ->and(AuditLogEntry::query()->where('action', 'template.company_active_set')->count())->toBe(1)
            ->and(AuditLogEntry::query()->where('action', 'template.company_active_set')->sole()->target_type)->toBe('UnitTemplateChoice');
    });
});

describe("a section leader's pick", function () {
    beforeEach(function () {
        $this->standard = $this->create->handle($this->co, $this->company, $this->type, 'Standard', 'standard', 'why');
        $this->training = $this->create->handle($this->co, $this->company, $this->type, 'Training week', 'training', 'why');
        $this->bravo = $this->create->handle($this->otherCo, $this->other, $this->type, 'Bravo own', 'bravo', 'why');
    });

    it('picks a switched-on template for their section, audited, and the pick persists', function () {
        $this->pick->handle($this->sl, $this->section->id, $this->training->id);
        $entry = AuditLogEntry::query()->latest('id')->first();

        expect($this->repo->choiceFor($this->section->id, $this->type)->template_id)->toBe($this->training->id)
            ->and($this->repo->choiceFor($this->sibling->id, $this->type))->toBeNull()
            ->and($entry->action)->toBe('template.picked')
            ->and($entry->actor_id)->toBe($this->sl->id)
            ->and($entry->target_type)->toBe('UnitTemplateChoice');
    });

    it('may pick the regiment default, and clear the pick to follow the company again', function () {
        $this->pick->handle($this->sl, $this->section->id, $this->default->id);
        expect($this->repo->choiceFor($this->section->id, $this->type)->template_id)->toBe($this->default->id);

        $this->pick->handle($this->sl, $this->section->id, null);
        $this->pick->handle($this->sl, $this->section->id, null);

        expect($this->repo->choiceFor($this->section->id, $this->type))->toBeNull()
            ->and(AuditLogEntry::query()->where('action', 'template.pick_cleared')->count())->toBe(1);
    });

    it("refuses a switched-off template, another company's and an id that names none, with the same answer", function (Closure $id) {
        $this->switch->handle($this->co, $this->training, false);

        expect(fn () => $this->pick->handle($this->sl, $this->section->id, $id($this)))->toThrow(InvalidTemplateException::class, 'not available to this section');
        expect($this->repo->choiceFor($this->section->id, $this->type))->toBeNull();
    })->with([
        'switched off' => [fn ($t) => $t->training->id],
        "another company's" => [fn ($t) => $t->bravo->id],
        'missing' => [fn () => 999999],
    ]);

    it('refuses a section outside write scope, or a staff grant, whether or not it exists', function () {
        $grantee = User::factory()->serving()->create();
        PortalRoleGrant::factory()->create(['user_id' => $grantee->id, 'unit_id' => $this->company->id]);

        expect(fn () => $this->pick->handle($this->sl, $this->sibling->id, $this->standard->id))->toThrow(TemplateForbiddenException::class)
            ->and(fn () => $this->pick->handle($this->sl, 999999, $this->standard->id))->toThrow(TemplateForbiddenException::class)
            ->and(fn () => $this->pick->handle($grantee, $this->section->id, $this->standard->id))->toThrow(TemplateForbiddenException::class);
    });

    it('lets the command above pick for a section, and refuses a unit that is no section', function () {
        $this->pick->handle($this->co, $this->section->id, $this->standard->id);

        expect($this->repo->choiceFor($this->section->id, $this->type)->template_id)->toBe($this->standard->id)
            ->and(fn () => $this->pick->handle($this->co, $this->platoon->id, $this->standard->id))->toThrow(InvalidTemplateException::class, 'not a section');
    });
});

it('never lets two companies bleed into each other', function () {
    $alpha = $this->create->handle($this->co, $this->company, $this->type, 'Alpha', 'alpha standard', 'why');
    $bravo = $this->create->handle($this->otherCo, $this->other, $this->type, 'Bravo', 'bravo standard', 'why');

    expect($this->repo->activeFor($this->company->id, $this->type)->body)->toBe('alpha standard')
        ->and($this->repo->activeFor($this->other->id, $this->type)->body)->toBe('bravo standard')
        ->and($this->repo->templatesOf($this->company->id, $this->type)->pluck('id')->all())->toBe([$alpha->id])
        ->and(UnitTemplateChoice::query()->count())->toBe(2)
        ->and($bravo->company_id)->toBe($this->other->id);
});

<?php

use App\Commands\Units\InvalidGuidonException;
use App\Commands\Units\SaveUnitGuidon;
use App\Commands\Units\SaveUnitGuidonHandler;
use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\UnitGuidon;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Inertia\Testing\AssertableInertia as Assert;

// #446: a company's, battalion's or the regiment's guidon, saved from the roster's generator as an audited Command.
// Who may: the unit's command team, the command above it, or an Admin (ScopeResolver::commandsUnit); nobody below it,
// no staff grant, never a view-as preview. Only `#rrggbb` colors and short, safe texts are stored.

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '2nd Battalion', '2-7', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/2-7', $this->battalion->id);
    $this->sibling = $units->create(UnitType::Company, 'Bravo Company', 'B/2-7', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/2-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/2-7', $this->platoon->id);
    $this->otherBattalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->otherCompany = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->otherBattalion->id);

    $this->design = ['primary' => '#1F2A44', 'secondary' => '#ffffff', 'topLeft' => '2/7', 'bottomRight' => 'A'];
});

function saveGuidon(object $test, User $user, int $unitId, array $design = [])
{
    return $test->actingAs($user)->from('/roster')->putJson("/units/{$unitId}/guidon", [...$test->design, ...$design]);
}

describe('who may save', function () {
    it('lets each of the company command billets save their own company', function (string $billet) {
        $user = User::factory()->commanding($this->company, ['billet' => "{$billet} A/2-7"])->create();

        saveGuidon($this, $user, $this->company->id)->assertRedirect('/roster');

        expect(UnitGuidon::query()->where('unit_id', $this->company->id)->sole()->design())
            ->toBe(['primary' => '#1f2a44', 'secondary' => '#ffffff', 'topLeft' => '2/7', 'bottomRight' => 'A']);
    })->with(['Commander', 'Executive Officer', 'First Sergeant']);

    it('refuses a company commander for a sibling company, the battalion above, or another battalion\'s company', function () {
        $co = User::factory()->commanding($this->company)->create();

        foreach ([$this->sibling, $this->battalion, $this->otherCompany, $this->regiment] as $unit) {
            saveGuidon($this, $co, $unit->id)->assertForbidden();
        }

        expect(UnitGuidon::query()->count())->toBe(0);
    });

    it('lets battalion command save their battalion and every company under it, and nothing outside it', function () {
        $bn = User::factory()->commanding($this->battalion)->create();

        foreach ([$this->battalion, $this->company, $this->sibling] as $unit) {
            saveGuidon($this, $bn, $unit->id)->assertRedirect();
        }
        saveGuidon($this, $bn, $this->otherCompany->id)->assertForbidden();
        saveGuidon($this, $bn, $this->regiment->id)->assertForbidden();

        expect(UnitGuidon::query()->pluck('unit_id')->sort()->values()->all())
            ->toBe(collect([$this->battalion->id, $this->company->id, $this->sibling->id])->sort()->values()->all());
    });

    it('lets regiment command save the regiment and any battalion or company', function () {
        $rgt = User::factory()->commanding($this->regiment)->create();

        foreach ([$this->regiment, $this->otherBattalion, $this->otherCompany] as $unit) {
            saveGuidon($this, $rgt, $unit->id)->assertRedirect();
        }

        expect(UnitGuidon::query()->count())->toBe(3);
    });

    it('lets an Admin save any unit that carries a guidon', function () {
        $admin = User::factory()->admin()->serving()->create();

        saveGuidon($this, $admin, $this->otherCompany->id)->assertRedirect();
        saveGuidon($this, $admin, $this->regiment->id)->assertRedirect();

        expect(UnitGuidon::query()->count())->toBe(2);
    });

    it('refuses the platoon and section leaders below the company, and a staff grant on it', function () {
        $pl = User::factory()->commanding($this->platoon)->create();
        $sl = User::factory()->commanding($this->section)->create();
        $granted = User::factory()->serving()->create();
        PortalRoleGrant::factory()->create(['user_id' => $granted->id, 'unit_id' => $this->company->id]);

        foreach ([$pl, $sl, $granted] as $user) {
            saveGuidon($this, $user, $this->company->id)->assertForbidden();
        }

        expect(UnitGuidon::query()->count())->toBe(0)
            ->and(AuditLogEntry::query()->where('action', SaveUnitGuidonHandler::ACTION)->count())->toBe(0);
    });

    it('gives platoons and sections no guidon, even for an Admin', function () {
        $admin = User::factory()->admin()->serving()->create();

        saveGuidon($this, $admin, $this->platoon->id)->assertUnprocessable()->assertJsonValidationErrors('guidon');
        saveGuidon($this, $admin, $this->section->id)->assertUnprocessable()->assertJsonValidationErrors('guidon');

        expect(UnitGuidon::query()->count())->toBe(0);
    });

    it('answers a unit that does not exist the same as one the leader does not command', function () {
        $co = User::factory()->commanding($this->company)->create();

        saveGuidon($this, $co, 999999)->assertForbidden();
    });

    it('refuses a save during view as, even for an Admin previewing the company', function () {
        $admin = User::factory()->admin()->serving()->create();
        $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->company->id])->assertSessionHasNoErrors();

        saveGuidon($this, $admin, $this->company->id)->assertForbidden();

        expect(UnitGuidon::query()->count())->toBe(0);
    });
});

describe('acting as', function () {
    it('records an Admin acting as the company commander as the Admin, on the commander\'s behalf', function () {
        $co = User::factory()->commanding($this->company)->create();
        $admin = User::factory()->admin()->create(['cav_id' => Trooper::factory()->create()->cav_id]);
        $this->actingAs($admin)->post('/act-as', ['kind' => 'user', 'id' => $co->id])->assertRedirect('/home');

        saveGuidon($this, $admin, $this->company->id)->assertRedirect();
        // The person acted as has the commander's permissions only: not another battalion's company.
        saveGuidon($this, $admin, $this->otherCompany->id)->assertForbidden();

        $entry = AuditLogEntry::query()->where('action', SaveUnitGuidonHandler::ACTION)->sole();
        expect($entry->actor_id)->toBe($admin->id)
            ->and($entry->on_behalf_of_id)->toBe($co->id)
            ->and(UnitGuidon::query()->sole()->updated_by)->toBe($admin->id);
    });

    it('records an Admin acting as the company\'s billet as the Admin, in that unit\'s place', function () {
        $admin = User::factory()->admin()->create(['cav_id' => Trooper::factory()->create()->cav_id]);
        $this->actingAs($admin)->post('/act-as', ['kind' => 'unit', 'id' => $this->company->id])->assertRedirect('/home');

        saveGuidon($this, $admin, $this->company->id)->assertRedirect();

        $entry = AuditLogEntry::query()->where('action', SaveUnitGuidonHandler::ACTION)->sole();
        expect($entry->actor_id)->toBe($admin->id)
            ->and($entry->on_behalf_of_unit_id)->toBe($this->company->id);
    });
});

describe('validation', function () {
    it('refuses a color that is not #rrggbb', function (string $color) {
        $admin = User::factory()->admin()->serving()->create();

        saveGuidon($this, $admin, $this->company->id, ['primary' => $color])
            ->assertUnprocessable()->assertJsonValidationErrors('primary');

        expect(UnitGuidon::query()->count())->toBe(0);
    })->with(['red', '#fff', '#12345g', '1f2a44', '#1f2a44ff', 'url(#x)', '#1f2a44" onload="x']);

    it('refuses text that is too long or outside the safe characters', function (string $field, string $text) {
        $admin = User::factory()->admin()->serving()->create();

        saveGuidon($this, $admin, $this->company->id, [$field => $text])
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        expect(UnitGuidon::query()->count())->toBe(0);
    })->with([
        'too long' => ['topLeft', '2/7CAVX'],
        'markup' => ['topLeft', '<b>'],
        'quote' => ['bottomRight', 'A"B'],
        'ampersand' => ['bottomRight', 'A&B'],
        'not latin' => ['bottomRight', 'Ä'],
        'tab' => ['topLeft', "2\t7"],
    ]);

    it('checks the fields again in the handler, whoever calls it', function (array $bad) {
        $admin = User::factory()->admin()->create();
        $command = new SaveUnitGuidon(...[
            'unitId' => $this->company->id, 'primaryColor' => '#1f2a44', 'secondaryColor' => '#ffffff', 'topLeftText' => '2/7', 'bottomRightText' => 'A',
            ...$bad,
        ]);

        expect(fn () => app(SaveUnitGuidonHandler::class)->handle($admin, $command))->toThrow(InvalidGuidonException::class)
            ->and(UnitGuidon::query()->count())->toBe(0);
    })->with([
        [['primaryColor' => "#1f2a44\n"]],
        [['secondaryColor' => '#fff']],
        [['topLeftText' => '<text>']],
        [['bottomRightText' => 'ABCDEFG']],
    ]);

    it('accepts empty text on a half', function () {
        $admin = User::factory()->admin()->serving()->create();

        saveGuidon($this, $admin, $this->battalion->id, ['topLeft' => '2/7', 'bottomRight' => ''])->assertRedirect();

        expect(UnitGuidon::query()->sole()->bottom_right_text)->toBe('');
    });
});

describe('the audit trail', function () {
    it('records each save and each change, and writes nothing for an unchanged one', function () {
        $co = User::factory()->commanding($this->company)->create();

        saveGuidon($this, $co, $this->company->id)->assertRedirect();
        saveGuidon($this, $co, $this->company->id)->assertRedirect();
        saveGuidon($this, $co, $this->company->id, ['secondary' => '#b22234'])->assertRedirect();

        $entries = AuditLogEntry::query()->where('action', SaveUnitGuidonHandler::ACTION)->orderBy('id')->get();
        $guidon = UnitGuidon::query()->sole();
        expect($entries)->toHaveCount(2)
            ->and($entries->pluck('actor_id')->unique()->all())->toBe([$co->id])
            ->and($entries->pluck('target_type')->unique()->all())->toBe(['UnitGuidon'])
            ->and($entries->pluck('target_id')->unique()->all())->toBe([(string) $guidon->id])
            ->and($guidon->secondary_color)->toBe('#b22234')
            ->and($guidon->updated_by)->toBe($co->id);
    });
});

describe('where it shows', function () {
    it('sends the saved guidon with the unit on the roster, its picker and Org structure, and null for a unit with none', function () {
        $co = User::factory()->commanding($this->company)->create();
        saveGuidon($this, $co, $this->company->id)->assertRedirect();
        $saved = ['primary' => '#1f2a44', 'secondary' => '#ffffff', 'topLeft' => '2/7', 'bottomRight' => 'A'];

        $this->actingAs($co)->get("/roster/{$this->company->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('unit.guidon', $saved)
            ->where('scopeOptions.0.guidon', $saved)
            ->where('scopeOptions.1.guidon', null)
            ->where('can.editGuidon', true));

        $this->actingAs($co)->get('/settings/org-structure')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('units', fn ($units) => collect($units)->firstWhere('id', $this->company->id)['guidon'] === $saved
                && collect($units)->firstWhere('id', $this->sibling->id)['guidon'] === null));
    });

    it('offers the generator only to those who may save, on a company or above, and never in a preview', function () {
        $co = User::factory()->commanding($this->company)->create();
        $pl = User::factory()->commanding($this->platoon)->create();
        $admin = User::factory()->admin()->serving()->create();

        $this->actingAs($co)->get("/roster/{$this->platoon->id}")->assertInertia(fn (Assert $page) => $page->where('can.editGuidon', false));
        $this->actingAs($pl)->get("/roster/{$this->platoon->id}")->assertInertia(fn (Assert $page) => $page->where('can.editGuidon', false));
        $this->actingAs($admin)->get("/roster/{$this->otherCompany->id}")->assertInertia(fn (Assert $page) => $page->where('can.editGuidon', true));

        $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->company->id]);
        $this->actingAs($admin)->get("/roster/{$this->company->id}")->assertInertia(fn (Assert $page) => $page->where('can.editGuidon', false));
    });
});

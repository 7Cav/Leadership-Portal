<?php

use App\Commands\Units\SaveUnitGuidonHandler;
use App\Models\AuditLogEntry;
use App\Models\UnitGuidon;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitGuidonRepository;
use App\Repositories\UnitRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// Guidon formats: band (the regiment's own 2/7 A guidon), diagonal (what every guidon saved before formats draws),
// top and bottom, and solid; each text left or centered; an optional large character at the hoist. And where the
// saved guidon shows, without a query per row.

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '2nd Battalion', '2-7', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Bravo Company', 'B/ACD', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/B/ACD', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/B/ACD', $this->platoon->id);
    $this->admin = User::factory()->admin()->serving()->create();

    // The ACD guidon: orange over white on the diagonal, a large 7 at the hoist, "ACD" on top and "B" below.
    $this->acd = [
        'format' => 'diagonal', 'primary' => '#f47a20', 'secondary' => '#ffffff', 'topLeft' => 'ACD', 'bottomRight' => 'B',
        'topAlign' => 'left', 'bottomAlign' => 'left', 'large' => '7',
    ];
});

function putGuidon(object $test, int $unitId, array $design)
{
    return $test->actingAs($test->admin)->from('/roster')->putJson("/units/{$unitId}/guidon", $design);
}

describe('formats', function () {
    it('saves the ACD guidon with its large character, and audits it', function () {
        putGuidon($this, $this->company->id, $this->acd)->assertRedirect('/roster');

        expect(UnitGuidon::query()->sole()->design())->toBe($this->acd)
            ->and(AuditLogEntry::query()->where('action', SaveUnitGuidonHandler::ACTION)->count())->toBe(1);
    });

    it('saves the Regimental HQ guidon, top and bottom with centered text and periods', function () {
        $design = [...$this->acd, 'format' => 'horizontal', 'primary' => '#b22234', 'topLeft' => 'U.S.', 'bottomRight' => '7', 'topAlign' => 'center', 'bottomAlign' => 'center', 'large' => ''];

        putGuidon($this, $this->regiment->id, $design)->assertRedirect();

        expect(UnitGuidon::query()->sole()->design())->toBe($design);
    });

    it("saves the regiment's 2/7 A guidon on the band format with a large 2", function () {
        $design = ['format' => 'band', 'primary' => '#1f2a44', 'secondary' => '#ffffff', 'topLeft' => '7', 'bottomRight' => 'A', 'topAlign' => 'left', 'bottomAlign' => 'left', 'large' => '2'];

        putGuidon($this, $this->company->id, $design)->assertRedirect('/roster');

        expect(UnitGuidon::query()->sole()->design())->toBe($design);
    });

    it('saves a solid guidon', function () {
        putGuidon($this, $this->battalion->id, [...$this->acd, 'format' => 'solid'])->assertRedirect();

        expect(UnitGuidon::query()->sole()->format)->toBe('solid');
    });

    it('logs a format change as a change', function () {
        putGuidon($this, $this->company->id, $this->acd)->assertRedirect();
        putGuidon($this, $this->company->id, $this->acd)->assertRedirect();
        putGuidon($this, $this->company->id, [...$this->acd, 'format' => 'horizontal'])->assertRedirect();

        expect(AuditLogEntry::query()->where('action', SaveUnitGuidonHandler::ACTION)->count())->toBe(2);
    });

    it('saves a design sent without the new fields as a diagonal guidon with no large character', function () {
        putGuidon($this, $this->company->id, ['primary' => '#1f2a44', 'secondary' => '#ffffff', 'topLeft' => '2/7', 'bottomRight' => 'A'])->assertRedirect();

        expect(UnitGuidon::query()->sole()->design())->toMatchArray(['format' => 'diagonal', 'topAlign' => 'left', 'bottomAlign' => 'left', 'large' => '']);
    });

    it('reads a row saved before formats as diagonal', function () {
        // The migration's defaults, as a row written before it gets them.
        DB::table('unit_guidons')->insert([
            'unit_id' => $this->company->id, 'primary_color' => '#1f2a44', 'secondary_color' => '#ffffff',
            'top_left_text' => '2/7', 'bottom_right_text' => 'A', 'created_at' => now(), 'updated_at' => now(),
        ]);

        expect(UnitGuidon::query()->sole()->design())->toMatchArray(['format' => 'diagonal', 'topAlign' => 'left', 'bottomAlign' => 'left', 'large' => '']);
    });

    it('refuses an unknown format, placement or large character with the field\'s error', function (string $field, string $value) {
        putGuidon($this, $this->company->id, [...$this->acd, $field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        expect(UnitGuidon::query()->count())->toBe(0);
    })->with([
        'format' => ['format', 'quartered'],
        'top placement' => ['topAlign', 'right'],
        'bottom placement' => ['bottomAlign', '<b>'],
        'large too long' => ['large', '777'],
        'large markup' => ['large', '<'],
        'large period' => ['large', '7.'],
    ]);
});

describe('where it shows', function () {
    it('lets a unit without its own guidon show the nearest one above it', function () {
        putGuidon($this, $this->company->id, $this->acd)->assertRedirect();
        $guidons = app(UnitGuidonRepository::class);

        expect($guidons->designsFor([$this->section])[$this->section->id])->toBeNull()
            ->and($guidons->designsFor([$this->section], inherit: true)[$this->section->id])->toBe($this->acd)
            ->and($guidons->designsFor([$this->regiment], inherit: true)[$this->regiment->id])->toBeNull();
    });

    it('reads the guidons of any number of units in one query', function () {
        putGuidon($this, $this->company->id, $this->acd)->assertRedirect();
        putGuidon($this, $this->battalion->id, [...$this->acd, 'format' => 'solid'])->assertRedirect();
        $units = [$this->regiment, $this->battalion, $this->company, $this->platoon, $this->section];

        DB::enableQueryLog();
        DB::flushQueryLog();
        app(UnitGuidonRepository::class)->designsFor($units, inherit: true);

        expect(DB::getQueryLog())->toHaveCount(1);
    });

    it('sends each unit\'s own guidon in the unit switcher\'s list, with no query per unit', function () {
        manUnits($this->section);
        putGuidon($this, $this->company->id, $this->acd)->assertRedirect();

        $count = function () {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->actingAs($this->admin)->getJson('/scope/units')->assertOk();

            return [count(DB::getQueryLog()), collect($response->json('units'))];
        };

        [$before, $units] = $count();
        expect($units->firstWhere('id', $this->company->id)['guidon'])->toBe($this->acd)
            ->and($units->firstWhere('id', $this->section->id)['guidon'])->toBeNull();

        // More units and another guidon: the same number of queries.
        $repo = app(UnitRepository::class);
        foreach (['C', 'D', 'E'] as $letter) {
            $more = $repo->create(UnitType::Company, "{$letter} Company", "{$letter}/2-7", $this->battalion->id);
            putGuidon($this, $more->id, $this->acd)->assertRedirect();
        }
        [$after] = $count();

        expect($after)->toBe($before);
    });

    it('shows the company\'s guidon in the crest spot of a section leader\'s switcher', function () {
        putGuidon($this, $this->company->id, $this->acd)->assertRedirect();
        $sl = User::factory()->commanding($this->section)->create();

        $this->actingAs($sl)->get('/home')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('scopeTop.0.id', $this->section->id)
            ->where('scopeTop.0.guidon', $this->acd));
    });

    it('sends the company\'s guidon to a section\'s roll call and report', function () {
        manUnits($this->section);
        putGuidon($this, $this->company->id, $this->acd)->assertRedirect();

        $this->actingAs($this->admin)->get("/rollcall/{$this->section->id}?week=".WeekOf::start(CarbonImmutable::now())->toDateString())->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('guidon', $this->acd));
        $this->actingAs($this->admin)->get("/reports/{$this->section->id}/".WeekOf::start(CarbonImmutable::now())->toDateString())
            ->assertInertia(fn (Assert $page) => $page->where('unit.guidon', $this->acd));
    });

    it('sends each order of battle header the nearest guidon, with no query per unit', function () {
        manUnits($this->section);
        putGuidon($this, $this->company->id, $this->acd)->assertRedirect();

        $count = function () {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->actingAs($this->admin)->get("/roster/{$this->battalion->id}")->assertOk();

            return [count(DB::getQueryLog()), collect($response->viewData('page')['props']['units'])];
        };

        [$before, $units] = $count();
        expect($units->firstWhere('id', $this->company->id)['headerGuidon'])->toBe($this->acd)
            ->and($units->firstWhere('id', $this->platoon->id)['headerGuidon'])->toBe($this->acd)
            ->and($units->firstWhere('id', $this->battalion->id)['headerGuidon'])->toBeNull();

        // Another platoon: the same number of queries.
        app(UnitRepository::class)->create(UnitType::Platoon, '2nd Platoon', '2/B/ACD', $this->company->id);
        [$after] = $count();

        expect($after)->toBe($before);
    });

    it('sends nothing new for a unit with no guidon above it', function () {
        manUnits($this->section);

        $this->actingAs($this->admin)->get("/rollcall/{$this->section->id}?week=".WeekOf::start(CarbonImmutable::now())->toDateString())->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('guidon', null));
    });
});

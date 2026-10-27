<?php

use App\Commands\Troopers\PlaceTrooperInSection;
use App\Commands\Troopers\PlaceTrooperInSectionHandler;
use App\Commands\Units\DeleteUnit;
use App\Integration\Cav\CavApi;
use App\Models\PlacementSource;
use App\Models\Trooper;
use App\Models\TrooperPlacement;
use App\Models\Unit;
use App\Models\UnitBilletMapping;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TrooperPlacementRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Sync\SyncRosterHandler;
use Carbon\CarbonImmutable;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

// The placement history (decided 2026-10-27): every change to a trooper's primary placement, by the roster sync or an
// Admin's manual placement, is recorded with its date, so the KPIs can read a past week against the section the
// trooper was in then and the milpac can show the move.

function phRow(string $positionId, string $username): array
{
    return [
        'user' => ['userId' => '3', 'username' => $username],
        'rank' => ['rankShort' => 'SPC', 'rankFull' => 'Specialist', 'rankImageUrl' => '', 'rankId' => '4'],
        'realName' => 'Real Name',
        'uniformUrl' => '',
        'roster' => 'ROSTER_TYPE_COMBAT',
        'primary' => ['positionTitle' => 'Rifleman', 'positionId' => $positionId],
        'secondaries' => [],
        'joinDate' => '2020-01-01',
        'promotionDate' => '2022-01-01',
        'discordId' => '',
        'lastForumPostDate' => '',
        'mos' => '11B',
        'consoleGamertag' => '',
    ];
}

/** @param array<string, array> $combat cav id => row */
function phSync(array $combat): void
{
    MockClient::destroyGlobal();
    MockClient::global([
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_COMBAT/lite' => MockResponse::make(['profiles' => $combat]),
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_RESERVE/lite' => MockResponse::make(['profiles' => []]),
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_ELOA/lite' => MockResponse::make(['profiles' => []]),
    ]);
    app(SyncRosterHandler::class)->handle();
}

/** @return list<array{unit: ?int, from: ?string, until: ?string, source: string}> */
function phHistory(Trooper $trooper): array
{
    return app(TrooperPlacementRepository::class)->historyOf($trooper->id)
        ->map(fn (TrooperPlacement $row) => [
            'unit' => $row->unit_id,
            'from' => $row->placed_from?->toDateTimeString(),
            'until' => $row->placed_until?->toDateTimeString(),
            'source' => $row->source->value,
        ])->all();
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $battalion->id);
    $this->sectionA = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
    $this->sectionB = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $platoon->id);
    $this->eloa = $units->create(UnitType::Group, 'Extended Leave of Absence', 'ELOA', $this->regiment->id, UnitKind::Eloa);
});

describe('written by the roster sync', function () {
    beforeEach(function () {
        app()->instance(CavApi::class, new CavApi(['base_url' => 'https://api.7cav.us', 'api_key' => 'cav7_test-key']));
        UnitBilletMapping::factory()->create(['unit_id' => $this->sectionA->id, 'cav_position_id' => 700]);
        UnitBilletMapping::factory()->create(['unit_id' => $this->sectionB->id, 'cav_position_id' => 701]);
    });

    it('records a first placement, a transfer and nothing for a sync that changes nothing', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 18:00:00', 'UTC'));
        phSync(['42' => phRow('700', 'Jarvis.A')]);
        $trooper = Trooper::where('cav_id', 42)->sole();

        $this->travelTo(CarbonImmutable::parse('2026-10-06 18:00:00', 'UTC'));
        phSync(['42' => phRow('700', 'Jarvis.A')]);

        $this->travelTo(CarbonImmutable::parse('2026-10-12 18:00:00', 'UTC'));
        phSync(['42' => phRow('701', 'Jarvis.A')]);

        expect(phHistory($trooper))->toBe([
            ['unit' => $this->sectionA->id, 'from' => '2026-10-05 18:00:00', 'until' => '2026-10-12 18:00:00', 'source' => 'sync'],
            ['unit' => $this->sectionB->id, 'from' => '2026-10-12 18:00:00', 'until' => null, 'source' => 'sync'],
        ])->and($trooper->fresh()->primary_section_id)->toBe($this->sectionB->id);
    });
});

it('keeps a placement from before the history began, open-ended into the past, when the trooper first moves', function () {
    $trooper = Trooper::factory()->create();
    $trooper->forceFill(['primary_section_id' => $this->sectionA->id])->save();

    $this->travelTo(CarbonImmutable::parse('2026-10-12 18:00:00', 'UTC'));
    app(TrooperRepository::class)->updatePrimarySections([$trooper->cav_id => $this->sectionB->id]);

    expect(phHistory($trooper))->toBe([
        ['unit' => $this->sectionA->id, 'from' => null, 'until' => '2026-10-12 18:00:00', 'source' => 'sync'],
        ['unit' => $this->sectionB->id, 'from' => '2026-10-12 18:00:00', 'until' => null, 'source' => 'sync'],
    ]);
});

it('records a move onto ELOA like any other, next to its "was"', function () {
    $trooper = Trooper::factory()->create();
    $trooper->forceFill(['primary_section_id' => $this->sectionA->id])->save();

    $this->travelTo(CarbonImmutable::parse('2026-10-12 18:00:00', 'UTC'));
    app(TrooperRepository::class)->updatePrimarySections([$trooper->cav_id => $this->eloa->id], [$this->eloa->id]);

    expect(collect(phHistory($trooper))->pluck('unit')->all())->toBe([$this->sectionA->id, $this->eloa->id])
        ->and($trooper->fresh()->last_line_unit_id)->toBe($this->sectionA->id);
});

it('records an Admin\'s manual placement as manual', function () {
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);

    $this->travelTo(CarbonImmutable::parse('2026-10-12 18:00:00', 'UTC'));
    app(PlaceTrooperInSectionHandler::class)->handle(User::factory()->admin()->create(), new PlaceTrooperInSection($trooper->id, $this->sectionB->id, 'Billet not on the roster yet.'));

    expect(phHistory($trooper))->toBe([
        ['unit' => $this->sectionB->id, 'from' => '2026-10-12 18:00:00', 'until' => null, 'source' => 'manual'],
    ]);
});

it('closes the placement when its unit is deleted, keeping the stretch with no unit', function () {
    $empty = app(UnitRepository::class)->create(UnitType::Section, 'Spare Section', '9/1/A/1-7', $this->sectionA->parent_id);
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);
    app(TrooperPlacementRepository::class)->record([$trooper->id => [null, $empty->id]], PlacementSource::Manual, CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC'));
    $trooper->forceFill(['primary_section_id' => $empty->id])->save();

    $this->travelTo(CarbonImmutable::parse('2026-10-12 18:00:00', 'UTC'));
    app(DeleteUnit::class)->handle(User::factory()->admin()->create(), $empty);

    expect(phHistory($trooper))->toBe([
        ['unit' => null, 'from' => '2026-10-01 00:00:00', 'until' => '2026-10-12 18:00:00', 'source' => 'manual'],
    ]);
});

describe('read week by week', function () {
    it('gives each week the sections the trooper was in, both in the week of the move', function () {
        $trooper = Trooper::factory()->create();
        $trooper->forceFill(['primary_section_id' => $this->sectionA->id])->save();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 18:00:00', 'UTC')); // the week of 10-10
        app(TrooperRepository::class)->updatePrimarySections([$trooper->cav_id => $this->sectionB->id]);

        $weeks = app(TrooperPlacementRepository::class)
            ->sectionsByWeek([$trooper->id], CarbonImmutable::parse('2026-10-03', 'UTC'), CarbonImmutable::parse('2026-10-17', 'UTC'));

        expect($weeks->weeks[$trooper->id])->toBe([
            '2026-10-03' => [$this->sectionA->id],
            '2026-10-10' => [$this->sectionA->id, $this->sectionB->id],
            '2026-10-17' => [$this->sectionB->id],
        ])->and($weeks->current)->toBe([$trooper->id => $this->sectionB->id]);
    });

    it('leaves out the weeks before a first placement and the weeks on HQ staff or in a group', function () {
        $trooper = Trooper::factory()->create(['primary_section_id' => null]);
        $this->travelTo(CarbonImmutable::parse('2026-10-05 18:00:00', 'UTC'));
        app(TrooperRepository::class)->updatePrimarySections([$trooper->cav_id => $this->sectionA->id]);
        $this->travelTo(CarbonImmutable::parse('2026-10-19 18:00:00', 'UTC'));
        app(TrooperRepository::class)->updatePrimarySections([$trooper->cav_id => $this->eloa->id], [$this->eloa->id]);
        $this->travelTo(CarbonImmutable::parse('2026-10-26 18:00:00', 'UTC'));
        app(TrooperRepository::class)->updatePrimarySections([$trooper->cav_id => $this->sectionB->id]);

        $weeks = app(TrooperPlacementRepository::class)
            ->sectionsByWeek([$trooper->id], CarbonImmutable::parse('2026-09-26', 'UTC'), CarbonImmutable::parse('2026-10-24', 'UTC'));

        expect($weeks->weeks[$trooper->id])->toBe([
            '2026-10-03' => [$this->sectionA->id],
            '2026-10-10' => [$this->sectionA->id],
            '2026-10-17' => [$this->sectionA->id],
            '2026-10-24' => [$this->sectionB->id],
        ]);
    });

    it('reads a trooper with no history, or one moved around it, as placed in their current unit every week', function () {
        $plain = Trooper::factory()->create();
        $plain->forceFill(['primary_section_id' => $this->sectionA->id])->save();
        $movedAround = Trooper::factory()->create(['primary_section_id' => null]);
        app(TrooperPlacementRepository::class)->record([$movedAround->id => [null, $this->sectionA->id]], PlacementSource::Sync, CarbonImmutable::parse('2026-10-12 00:00:00', 'UTC'));
        $movedAround->forceFill(['primary_section_id' => $this->sectionB->id])->save();

        $weeks = app(TrooperPlacementRepository::class)
            ->sectionsByWeek([$plain->id, $movedAround->id], CarbonImmutable::parse('2026-10-03', 'UTC'), CarbonImmutable::parse('2026-10-10', 'UTC'));

        expect($weeks->weeks)->toBe([
            $plain->id => ['2026-10-03' => [$this->sectionA->id], '2026-10-10' => [$this->sectionA->id]],
            $movedAround->id => ['2026-10-03' => [$this->sectionB->id], '2026-10-10' => [$this->sectionB->id]],
        ]);
    });

    it('leaves out a trooper placed nowhere now', function () {
        $unplaced = Trooper::factory()->create(['primary_section_id' => null]);

        $weeks = app(TrooperPlacementRepository::class)
            ->sectionsByWeek([$unplaced->id], CarbonImmutable::parse('2026-10-03', 'UTC'), CarbonImmutable::parse('2026-10-10', 'UTC'));

        expect($weeks->weeks)->toBe([])->and($weeks->current)->toBe([]);
    });
});

<?php

namespace App\Repositories\TutorialPracticeRepository;

use App\Models\Attendance;
use App\Models\Game;
use App\Models\Loa;
use App\Models\LoaParseStatus;
use App\Models\LoaType;
use App\Models\PageVisit;
use App\Models\PlacementSource;
use App\Models\PlatoonTrainingTopic;
use App\Models\PracticeCapturePlayer;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\SectionPracticeSlot;
use App\Models\StaffReport;
use App\Models\TelemetryInterface;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\TrooperPlacement;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Repositories\TutorialPracticeRepository;
use Carbon\CarbonInterface;

class Eloquent implements TutorialPracticeRepository
{
    public function copyUser(int $id, int $forumUserId, ?int $cavId, string $username, bool $isAdmin): User
    {
        $user = new User;
        $user->forceFill([
            'id' => $id,
            'forum_user_id' => $forumUserId,
            'cav_id' => $cavId,
            'username' => $username,
            'email' => null,
            'is_admin' => $isAdmin,
        ])->save();

        return $user->refresh();
    }

    public function practiceUser(int $forumUserId, int $cavId, string $username): User
    {
        $user = new User;
        $user->forceFill(['forum_user_id' => $forumUserId, 'cav_id' => $cavId, 'username' => $username, 'email' => null, 'is_admin' => false])->save();

        return $user;
    }

    public function user(int $id): ?User
    {
        return User::query()->find($id);
    }

    public function unit(?Unit $parent, UnitType $type, string $name, string $callsign): Unit
    {
        $unit = new Unit;
        $unit->forceFill([
            'parent_id' => $parent?->id,
            'type' => $type,
            'kind' => UnitKind::Line,
            'name' => $name,
            'callsign' => $callsign,
            'path' => '',
        ])->save();
        $unit->forceFill(['path' => ($parent?->path ?? '').$unit->id.'/'])->save();

        return $unit;
    }

    public function game(string $name, string $slug, string $abbreviation): Game
    {
        return Game::query()->create([
            'slug' => $slug,
            'name' => $name,
            'abbreviation' => $abbreviation,
            'id_kinds' => ['Player ID'],
            'interface' => TelemetryInterface::None,
        ]);
    }

    public function linkEveryone(Game $game): void
    {
        Trooper::query()->pluck('cav_id', 'id')->each(fn ($cavId, $id) => TrooperGameIdentity::query()->firstOrCreate(
            ['trooper_id' => $id, 'game_id' => $game->id],
            ['platform_id' => 'practice-'.$cavId, 'id_kind' => 'Player ID'],
        ));
    }

    public function setUnitGame(Unit $unit, Game $game): void
    {
        $unit->forceFill(['game_id' => $game->id])->save();
    }

    public function trooper(int $cavId, ?int $forumUserId, string $rankShort, string $rank, string $username, string $billet, Unit $unit, CarbonInterface $since, ?Unit $commands = null, TrooperStatus $status = TrooperStatus::Combat): Trooper
    {
        $position = null;
        if ($commands !== null) {
            $position = 900_000_000 + $cavId % 1_000_000;
            UnitCommandMapping::query()->create(['unit_id' => $commands->id, 'cav_position_id' => $position]);
        }

        $trooper = Trooper::query()->create([
            'cav_id' => $cavId,
            'username' => $username,
            'forum_user_id' => $forumUserId,
            'rank' => $rank,
            'rank_short' => $rankShort,
            'billet' => $billet,
            'mos' => '11B',
            'tis' => $since->subYears(1)->toDateString(),
            'tig' => $since->toDateString(),
            'status' => $status,
            'cav_position_id' => $position,
        ]);
        $trooper->forceFill(['primary_section_id' => $unit->id, 'placement_source' => PlacementSource::Sync])->save();
        TrooperPlacement::query()->create([
            'trooper_id' => $trooper->id,
            'unit_id' => $unit->id,
            'placed_from' => $since,
            'placed_until' => null,
            'source' => PlacementSource::Sync->value,
        ]);

        return $trooper;
    }

    public function loa(Trooper $trooper, CarbonInterface $from, CarbonInterface $until): void
    {
        Loa::query()->create([
            'trooper_id' => $trooper->id,
            'forum_thread_id' => 900_000 + $trooper->id,
            'forum_node_id' => 0,
            'type' => LoaType::Loa,
            'starts_at' => $from->toDateString(),
            'ends_at' => $until->toDateString(),
            'parse_status' => LoaParseStatus::Ok,
            // Not a real thread: the tutorial has no forum. The page shows it as a link like any LOA.
            'source_thread' => 'https://7cav.us/threads/practice-loa.'.(900_000 + $trooper->id).'/',
            'posted_at' => $from,
        ]);
    }

    public function slot(Unit $section, int $weekday, int $startMinute, CarbonInterface $since): void
    {
        SectionPracticeSlot::query()->create([
            'section_id' => $section->id,
            'weekday' => $weekday,
            'start_minute' => $startMinute,
            'effective_from' => $since->toDateString(),
        ]);
    }

    public function captured(SectionPractice $practice, CarbonInterface $at, CarbonInterface $windowStart, CarbonInterface $windowEnd): SectionPractice
    {
        $practice->forceFill([
            'server_ref' => 'practice',
            'captured_at' => $at,
            'capture_source' => 'Practice server',
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
        ])->save();

        return $practice;
    }

    public function capturePlayer(SectionPractice $practice, Game $game, string $playerName, string $platformId, ?Trooper $trooper): void
    {
        PracticeCapturePlayer::query()->create([
            'section_practice_id' => $practice->id,
            'game_id' => $game->id,
            'player_name' => $playerName,
            'platform_id' => $platformId,
            'trooper_id' => $trooper?->id,
        ]);
    }

    public function unitByCallsign(string $callsign): ?Unit
    {
        return Unit::query()->where('callsign', $callsign)->first();
    }

    public function trooperByUsername(string $username): ?Trooper
    {
        return Trooper::query()->where('username', $username)->first();
    }

    public function progress(int $userId, ?int $sectionId, ?int $reportUnitId, ?int $demoTrooperId, ?int $personaTrooperId, CarbonInterface $week): array
    {
        $practice = $sectionId === null ? null : SectionPractice::query()->where('section_id', $sectionId)->where('week_start', $week)->first();
        $demo = $practice === null || $demoTrooperId === null ? null
            : Attendance::query()->where('section_practice_id', $practice->id)->where('trooper_id', $demoTrooperId)->first();
        $report = $reportUnitId === null ? null : WeeklyReport::query()->where('unit_id', $reportUnitId)->where('week_start', $week)->first();
        $sent = $report?->status === WeeklyReportStatus::Sent;

        return [
            'visits' => PageVisit::query()->where('user_id', $userId)->pluck('url')->map(fn ($url) => (string) $url)->unique()->values()->all(),
            'demoSigned' => $demo !== null && $demo->rollcall !== RollcallSignature::Unsigned,
            'demoMarked' => $demo?->status !== null,
            'confirmed' => $practice?->confirmed_at !== null,
            'posted' => $practice?->rollcall_posted_at !== null,
            // A platoon writes its subject as the training topic for its sections, which is saved on its own. A sent
            // report ticks both, so a reader who sent without a note is not held on the step.
            'subject' => $sent || filled($report?->training_subject)
                || ($reportUnitId !== null && PlatoonTrainingTopic::query()->where('unit_id', $reportUnitId)->where('week_start', $week)->exists()),
            'note' => $sent || filled($report?->notes),
            'sent' => $sent,
            'staffSent' => $personaTrooperId !== null && StaffReport::query()->where('trooper_id', $personaTrooperId)->exists(),
        ];
    }
}

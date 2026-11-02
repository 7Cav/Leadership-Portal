<?php

namespace App\Commands\GameIdentities;

use App\Authorization\ScopeResolver;
use App\Models\Game;
use App\Models\IdentitySource;
use App\Models\IdKindFormat;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\User;
use App\Repositories\AuditedBatch;
use App\Repositories\GameIdentityLinkException;
use App\Repositories\GamePlayerDismissalRepository;
use App\Repositories\GameRepository;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperRepository;
use App\Support\Audit\AuditEnvelope;
use Illuminate\Support\Collection;

/**
 * The bulk import of game identities from a text file (#174 follow-up): a preview of what each line would do, then
 * one audited batch (`game_identity.imported`) that applies every line the preview would add or change.
 *
 * Each line is judged as a single save on Game identities would be ({@see SetTrooperGameIdentityHandler}): the trooper
 * must be serving and placed in the actor's write scope (AD-8), the game known and not retired, the kind one the game
 * asks for (the game's default when left out), the ID of its kind's shape (#450), not another trooper's on that game,
 * and, for a Steam ID, known to Steam. A trooper who already holds a different ID on the game is skipped unless the
 * leader ticks "replace existing". A second line for the same trooper and game, or the same ID on the same game, is
 * refused. Steam is asked about at most {@see self::STEAM_LOOKUPS} IDs per run; the rest, or every one when Steam has
 * no key or does not answer, is "not checked" and saved as a single save would save it.
 *
 * The import judges the text again and refuses the whole batch when the lines it would apply are not the ones the
 * preview showed. Inside the transaction each game is locked shared and each trooper's identity read under lock, so
 * a change made meanwhile rolls everything back: all the lines or none. New rows are `imported`; a change of kind only
 * keeps how the row was made.
 */
class ImportGameIdentities
{
    /** The most Steam IDs asked about in one preview or import, so a large file does not wait on Steam for long. */
    public const STEAM_LOOKUPS = 20;

    public function __construct(
        private readonly IdentityImportParser $parser,
        private readonly ScopeResolver $scope,
        private readonly TrooperRepository $troopers,
        private readonly TrooperGameIdentityRepository $identities,
        private readonly GamePlayerDismissalRepository $dismissals,
        private readonly GameRepository $games,
        private readonly AuditEnvelope $audit,
        private readonly SteamAccountCheck $steam,
    ) {}

    /**
     * What each line would do, in file order, and the counts by status. Writes nothing.
     *
     * Each line: `line` (its number in the text), `trooper`, `game`, `idKind`, `platformId` (as read, or as resolved
     * when known), `status` (`add`, `change`, `skip`, `unchanged` or `refused`), `reason` (why it is skipped or
     * refused), `current` (the ID the trooper holds there now, for a change or a skip) and `steam` (`checked`,
     * `not_checked`, or null when the ID is not a Steam ID or the line is not saved).
     *
     * @return array{lines: list<array<string, mixed>>, counts: array<string, int>}
     *
     * @throws GameIdentityForbiddenException when the actor can change no trooper at all.
     * @throws IdentityImportException when the text cannot be read.
     */
    public function preview(User $actor, string $text, bool $replace): array
    {
        $lines = array_map(fn (array $line) => $this->publicLine($line), $this->judge($actor, $text, $replace));
        $counts = array_fill_keys(['add', 'change', 'skip', 'unchanged', 'refused'], 0);

        foreach ($lines as $line) {
            $counts[$line['status']]++;
        }

        return ['lines' => $lines, 'counts' => $counts];
    }

    /**
     * Apply every line the preview would add or change, in one audited batch. `$expected` is the line numbers the
     * preview showed as to be saved; anything else refuses the whole import.
     *
     * @param  list<int>  $expected
     * @return int the number of identities saved
     *
     * @throws GameIdentityForbiddenException when the actor can change no trooper at all.
     * @throws IdentityImportException when the text cannot be read, nothing is to be saved, or the lines changed since the preview.
     * @throws GameIdentityLinkException when another trooper claims an ID meanwhile; nothing is written.
     * @throws UnknownIdKindException when an Admin removes a kind or retires a game meanwhile; nothing is written.
     */
    public function import(User $actor, string $text, bool $replace, array $expected): int
    {
        $apply = array_values(array_filter($this->judge($actor, $text, $replace), fn (array $line) => in_array($line['status'], ['add', 'change'], true)));

        if ($apply === []) {
            throw IdentityImportException::nothingToImport();
        }

        $lineNumbers = array_column($apply, 'line');
        $wanted = array_map('intval', $expected);
        sort($wanted);

        if ($lineNumbers !== $wanted) {
            throw IdentityImportException::outdated();
        }

        $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'game_identity.imported',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($apply) {
                $rows = [];
                $locked = [];

                foreach ($apply as $line) {
                    /** @var Game $game */
                    $game = $locked[$line['gameModel']->id] ??= $this->games->lock($line['gameModel']->id, exclusive: false);

                    if ($game->isRetired() || ! $game->hasIdKind($line['idKind'])) {
                        throw UnknownIdKindException::for($game, $line['idKind']);
                    }

                    // The trooper's identity must still be what the preview saw, else the leader agreed to something else.
                    $held = $this->identities->lockForTrooper($line['trooperId'], $game);

                    if ($held?->platform_id !== $line['current']) {
                        throw IdentityImportException::outdated();
                    }

                    $source = $held?->platform_id === $line['platformId'] ? $held->source : IdentitySource::Imported;
                    $row = $this->identities->link($line['trooperId'], $game, $line['platformId'], $line['idKind'], $source);
                    $this->dismissals->forget($game, $line['platformId']);
                    $rows[] = $row;
                }

                return new AuditedBatch(...$rows);
            },
        );

        return count($apply);
    }

    /**
     * Every line, judged.
     *
     * @return list<array<string, mixed>>
     */
    private function judge(User $actor, string $text, bool $replace): array
    {
        $writable = $this->scope->writeScope($actor);

        if ($writable->isEmpty()) {
            throw GameIdentityForbiddenException::forTrooper(0);
        }

        $rows = $this->parser->parse($text);
        $games = $this->games->all();
        $names = array_map(fn (array $row) => $this->username($row['cells'][0]), $rows);
        $ids = $this->troopers->idsByUsername(array_values(array_filter($names)));
        $troopers = $this->troopers->byIds(array_values(array_unique(array_merge(...array_values($ids)))))->keyBy('id');
        $held = $this->identities->forTroopers($troopers->keys()->all())->groupBy('trooper_id');
        $seenPair = [];
        $seenId = [];
        $steamLeft = self::STEAM_LOOKUPS;
        $steamDown = false;
        $judged = [];

        foreach ($rows as $index => $row) {
            $line = $this->read($row);

            if ($line['status'] === 'refused') {
                $judged[] = $line;

                continue;
            }

            $matches = $ids[mb_strtolower($names[$index])] ?? [];
            $trooper = count($matches) === 1 ? $troopers->get($matches[0]) : null;
            $game = $this->findGame($games, $line['game']);
            $refusal = match (true) {
                $matches === [] => "No trooper is named {$line['trooper']}.",
                count($matches) > 1 => "More than one trooper is named {$line['trooper']}.",
                $trooper === null || ! $trooper->status->isServing() => "{$line['trooper']} is not serving.",
                $trooper->primary_section_id === null || ! $writable->contains($trooper->primary_section_id) => "{$trooper->display_name} is not in a unit you can change.",
                $game === null => "No game is called {$line['game']}.",
                $game->isRetired() => "{$game->name} is retired and takes no new identities.",
                default => null,
            };

            if ($refusal !== null) {
                $judged[] = [...$line, 'status' => 'refused', 'reason' => $refusal];

                continue;
            }

            /** @var Trooper $trooper */
            /** @var Game $game */
            $line['trooper'] = $trooper->display_name;
            $line['trooperId'] = $trooper->id;
            $line['game'] = $game->name;
            $line['gameModel'] = $game;
            $judged[] = $this->judgeIdentity($line, $trooper, $game, $held->get($trooper->id) ?? collect(), $replace, $seenPair, $seenId);
        }

        // Steam last, and only for the lines that would be saved, so a refused line never spends a lookup.
        foreach ($judged as &$line) {
            if (! in_array($line['status'], ['add', 'change'], true) || ! $this->steam->applies($line['idKind'])) {
                continue;
            }

            if ($line['current'] === $line['platformId']) {
                // A change of kind only: the ID was already linked, as a single save does not ask again.
                continue;
            }

            $verdict = null;

            if (! $steamDown && $steamLeft > 0) {
                $steamLeft--;
                $verdict = $this->steam->verdict($line['idKind'], $line['platformId']);
                // No answer once means none to come: the rest are not checked rather than each waiting on a timeout.
                $steamDown = $verdict === null;
            }

            if ($verdict === false) {
                $line = [...$line, 'status' => 'refused', 'reason' => 'Steam has no account with this ID.', 'steam' => 'checked'];
            } else {
                $line['steam'] = $verdict === true ? 'checked' : 'not_checked';
            }
        }

        return $judged;
    }

    /**
     * The line's columns, or the line refused for having the wrong number of them.
     *
     * @param  array{line: int, cells: list<string>}  $row
     * @return array<string, mixed>
     */
    private function read(array $row): array
    {
        $cells = $row['cells'];
        $base = ['line' => $row['line'], 'trooper' => $cells[0], 'game' => $cells[1] ?? '', 'idKind' => null, 'platformId' => '', 'status' => 'refused', 'reason' => null, 'current' => null, 'steam' => null];

        if (count($cells) === 3) {
            return [...$base, 'platformId' => $cells[2], 'status' => 'pending'];
        }

        if (count($cells) === 4) {
            return [...$base, 'idKind' => $cells[2] === '' ? null : $cells[2], 'platformId' => $cells[3], 'status' => 'pending'];
        }

        return [...$base, 'reason' => 'Expected trooper, game, ID kind and ID, separated by commas or tabs.'];
    }

    /**
     * The kind, the ID's shape, the duplicates in the file, what the trooper holds now and who else holds the ID.
     *
     * @param  array<string, mixed>  $line
     * @param  Collection<int, TrooperGameIdentity>  $mine
     * @param  array<string, int>  $seenPair  "trooper#game" => the line that first named it
     * @param  array<string, array{line: int, trooper: string}>  $seenId  "game#id" => the line that first gave it
     * @return array<string, mixed>
     */
    private function judgeIdentity(array $line, Trooper $trooper, Game $game, Collection $mine, bool $replace, array &$seenPair, array &$seenId): array
    {
        $refuse = fn (string $reason) => [...$line, 'status' => 'refused', 'reason' => $reason];
        $kind = $line['idKind'] === null ? $game->defaultIdKind() : collect($game->id_kinds)->first(fn (string $k) => mb_strtolower($k) === mb_strtolower($line['idKind']));

        if ($kind === null) {
            return $refuse("{$game->name} does not use {$line['idKind']}. It uses ".implode(', ', $game->id_kinds).'.');
        }

        $line['idKind'] = $kind;

        if ($line['platformId'] === '') {
            return $refuse('The ID is empty.');
        }

        $existing = $mine->firstWhere('game_id', $game->id);
        $line['current'] = $existing?->platform_id;
        $platformId = $line['platformId'];
        $format = IdKindFormat::for($kind);
        // As a single save: an ID kept under the kind it already has is not checked again.
        $unchanged = $existing !== null && $existing->platform_id === $platformId && $existing->id_kind === $kind;

        if ($format !== null && ! $unchanged) {
            $platformId = $format->canonical($platformId);

            if (! $format->accepts($platformId)) {
                return $refuse($format->message());
            }
        }

        $line['platformId'] = $platformId;
        $pair = "{$trooper->id}#{$game->id}";
        $idKey = $game->id.'#'.$platformId;

        if (isset($seenPair[$pair])) {
            return $refuse("Line {$seenPair[$pair]} already gives {$trooper->display_name} an identity on {$game->name}.");
        }

        if (isset($seenId[$idKey])) {
            return $refuse("Line {$seenId[$idKey]['line']} already gives this ID to {$seenId[$idKey]['trooper']} on {$game->name}.");
        }

        $seenPair[$pair] = $line['line'];
        $seenId[$idKey] = ['line' => $line['line'], 'trooper' => $trooper->display_name];

        if ($existing !== null && $existing->platform_id === $platformId && $existing->id_kind === $kind) {
            return [...$line, 'status' => 'unchanged'];
        }

        $owner = $this->identities->findByPlatformId($game, $platformId);

        if ($owner !== null && $owner->trooper_id !== $trooper->id) {
            $name = $this->troopers->find($owner->trooper_id)?->display_name ?? 'another trooper';

            return $refuse("Already linked to {$name} on {$game->name}.");
        }

        if ($existing !== null && $existing->platform_id !== $platformId && ! $replace) {
            return [...$line, 'status' => 'skip', 'reason' => "Already has {$existing->platform_id}. Tick Replace existing identities to change it."];
        }

        return [...$line, 'status' => $existing === null ? 'add' : 'change'];
    }

    /** The game named by its name, abbreviation or slug, ignoring case. */
    private function findGame(Collection $games, string $name): ?Game
    {
        $name = mb_strtolower($name);

        return $name === '' ? null : $games->first(fn (Game $game) => in_array($name, [mb_strtolower($game->name), mb_strtolower((string) $game->abbreviation), mb_strtolower($game->slug)], true));
    }

    /** The Cav name in the trooper column: its last word, so "SGT Novak.E" reads as "Novak.E". */
    private function username(string $cell): string
    {
        $words = preg_split('/\s+/u', trim($cell)) ?: [];

        return (string) end($words);
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function publicLine(array $line): array
    {
        unset($line['gameModel'], $line['trooperId']);

        return $line;
    }
}

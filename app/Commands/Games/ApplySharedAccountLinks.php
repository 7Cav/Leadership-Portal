<?php

namespace App\Commands\Games;

use App\Commands\GameIdentities\GameRetiredException;
use App\Models\Game;
use App\Models\IdentitySource;
use App\Models\SharedAccount;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\User;
use App\Repositories\AuditedBatch;
use App\Repositories\GameRepository;
use App\Repositories\TrooperGameIdentityRepository;
use App\Support\Audit\AuditEnvelope;
use Illuminate\Support\Collection;

/**
 * An Admin's one-click backfill (#174, AD-19): a game that asks for a Steam or Epic ID gets a row for every trooper
 * whose account of that kind is already linked on another game, with source from_account, as one audited action
 * (AD-16). Never run on its own: Settings, Games offers it when there is something to apply. The caller checks the
 * actor is an Admin, as for the other game writes.
 *
 * It only ever adds rows, never changes one: a trooper is left out when they already have an identity on the game, when their other games hold more than one ID
 * of that account (there is no telling which is right), or when another trooper already holds the ID on this game.
 * With a game that asks for both, the kind listed first is applied first.
 */
class ApplySharedAccountLinks
{
    public function __construct(
        private readonly GameRepository $games,
        private readonly TrooperGameIdentityRepository $identities,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * What a backfill on `$game` would write, from `$all` (every identity, with its game and trooper): one entry per
     * trooper, the account's ID from their other game and the kind it is entered as here.
     *
     * @param  Collection<int, TrooperGameIdentity>  $all
     * @return Collection<int, array{trooperId: int, platformId: string, idKind: string, trooper: ?Trooper}>
     */
    public static function pending(Game $game, Collection $all): Collection
    {
        if ($game->isRetired()) {
            return new Collection;
        }

        $here = $all->where('game_id', $game->id);
        $held = $here->pluck('trooper_id')->flip();
        $taken = $here->map(fn (TrooperGameIdentity $row) => mb_strtolower($row->platform_id))->flip();
        $elsewhere = $all->where('game_id', '!=', $game->id);
        $pending = [];

        foreach ($game->id_kinds as $kind) {
            $account = SharedAccount::of($kind);

            if ($account === null) {
                continue;
            }

            $elsewhere->filter(fn (TrooperGameIdentity $row) => SharedAccount::of((string) $row->id_kind) === $account)
                ->groupBy('trooper_id')
                ->each(function (Collection $rows, $trooperId) use ($held, $taken, $kind, &$pending) {
                    $ids = $rows->pluck('platform_id')->unique();

                    if ($held->has($trooperId) || isset($pending[$trooperId]) || $ids->count() !== 1 || $taken->has(mb_strtolower($ids->first()))) {
                        return;
                    }

                    $pending[$trooperId] = [
                        'trooperId' => (int) $trooperId,
                        'platformId' => $ids->first(),
                        'idKind' => $kind,
                        'trooper' => $rows->first()->trooper,
                    ];
                });
        }

        return new Collection(array_values($pending));
    }

    /**
     * @return int how many identities were written; none writes nothing and logs nothing.
     *
     * @throws GameRetiredException when the game is retired.
     */
    public function handle(User $actor, Game $game): int
    {
        if ($game->isRetired()) {
            throw GameRetiredException::for($game);
        }

        if (self::pending($game, $this->identities->all())->isEmpty()) {
            return 0;
        }

        $batch = $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'game_identity.account_backfilled',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($game) {
                // Worked out again under the game's lock, so a kind removed or a link made since is seen.
                $stored = $this->games->lock($game->id, exclusive: false);
                $rows = self::pending($stored, $this->identities->all())
                    ->map(fn (array $link) => $this->identities->link($link['trooperId'], $stored, $link['platformId'], $link['idKind'], IdentitySource::FromAccount)
                        ->setRelation('game', $stored)->setRelation('trooper', $link['trooper']));

                return new AuditedBatch($stored, ...$rows->all());
            },
        );

        return count($batch->members);
    }
}

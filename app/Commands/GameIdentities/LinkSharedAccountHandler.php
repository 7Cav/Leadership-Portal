<?php

namespace App\Commands\GameIdentities;

use App\Authorization\ScopeResolver;
use App\Models\Game;
use App\Models\IdentitySource;
use App\Models\IdKindFormat;
use App\Models\SharedAccount;
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
 * Links one Steam or Epic account to every active game that asks for that account (#174), as one audited action
 * (AD-16) that writes an ordinary `trooper_game_identities` row per game, each with source from_account. Scope-gated
 * like a single link (AD-8).
 *
 * All or nothing: when the ID already belongs to another trooper on any of those games, or the trooper already holds a
 * different ID of that account on one, nothing is written and the refusal names each trooper and game. A Steam ID is
 * asked about on Steam first ({@see SteamAccountCheck}), and one Steam has no account for is refused. A game where
 * the trooper already holds this same ID is left as it is. A game with no server feed yet is covered too: the
 * identity also helps a leader mark by hand.
 */
class LinkSharedAccountHandler
{
    use AuthorizesGameIdentityChange;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly TrooperRepository $troopers,
        private readonly TrooperGameIdentityRepository $identities,
        private readonly GamePlayerDismissalRepository $dismissals,
        private readonly GameRepository $games,
        private readonly AuditEnvelope $audit,
        private readonly SteamAccountCheck $steam,
    ) {}

    /**
     * The rows written, none when every game already had this ID.
     *
     * @return Collection<int, TrooperGameIdentity>
     *
     * @throws GameIdentityForbiddenException when the trooper is outside the actor's scope.
     * @throws GameRetiredException when the form's game is retired.
     * @throws UnknownIdKindException when the form's game does not have the kind.
     * @throws SharedAccountException when the kind is not a Steam or Epic ID, or a game is refused.
     * @throws InvalidGameIdentityException when the ID is empty once cleaned, lacks its kind's shape, or Steam has no such account.
     * @throws GameIdentityLinkException when another trooper claims the ID on a game meanwhile; nothing stays written.
     */
    public function handle(User $actor, LinkSharedAccount $command): Collection
    {
        $trooper = $this->authorizedTrooper($actor, $command->trooperId, $this->scope, $this->troopers);

        if ($command->game->isRetired()) {
            throw GameRetiredException::for($command->game);
        }

        if (! $command->game->hasIdKind($command->idKind)) {
            throw UnknownIdKindException::for($command->game, $command->idKind);
        }

        $account = SharedAccount::of($command->idKind) ?? throw SharedAccountException::notShared($command->idKind);

        if ($command->platformId === '') {
            throw InvalidGameIdentityException::emptyId();
        }

        $platformId = $command->platformId;
        $format = IdKindFormat::for($command->idKind);

        if ($format !== null) {
            $platformId = $format->canonical($platformId);

            if (! $format->accepts($platformId)) {
                throw InvalidGameIdentityException::badFormat($format);
            }
        }

        $covered = $this->games->all()->reject(fn (Game $game) => $game->isRetired() || $account->kindOn($game) === null)->values();
        $mine = $this->identities->forTrooper($trooper->id)->keyBy('game_id');

        $different = $covered->filter(fn (Game $game) => $mine->has($game->id) && $mine->get($game->id)->platform_id !== $platformId);

        if ($different->isNotEmpty()) {
            throw SharedAccountException::differentId($command->idKind, $different->pluck('name')->values()->all());
        }

        $owners = $covered->map(function (Game $game) use ($platformId, $trooper) {
            $owner = $this->identities->findByPlatformId($game, $platformId);

            return $owner === null || $owner->trooper_id === $trooper->id
                ? null
                : ($this->troopers->find($owner->trooper_id)?->display_name ?? 'another trooper')." on {$game->name}";
        })->filter()->values();

        if ($owners->isNotEmpty()) {
            throw SharedAccountException::linkedToOthers($owners->all());
        }

        $toLink = $covered->reject(fn (Game $game) => $mine->has($game->id))->values();

        if ($toLink->isEmpty()) {
            return new Collection;
        }

        $this->steam->check($command->idKind, $platformId);

        $batch = $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'game_identity.account_linked',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($toLink, $account, $trooper, $command, $platformId) {
                $rows = [];

                foreach ($toLink as $game) {
                    // As a single link does: the game read under a shared lock, so a kind an Admin removed since is refused.
                    $stored = $this->games->lock($game->id, exclusive: false);
                    $kind = $account->kindOn($stored);

                    if ($stored->isRetired() || $kind === null) {
                        throw UnknownIdKindException::for($stored, $command->idKind);
                    }

                    $row = $this->identities->link($trooper->id, $stored, $platformId, $kind, IdentitySource::FromAccount);
                    $row->setRelation('game', $stored)->setRelation('trooper', $trooper);
                    $this->dismissals->forget($stored, $platformId);
                    $rows[] = $row;
                }

                return new AuditedBatch($trooper, ...$rows);
            },
        );

        return new Collection($batch->members);
    }
}

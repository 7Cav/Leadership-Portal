<?php

namespace App\Commands\Counseling;

use App\Commands\Auth\ForumPmGrant;
use App\Integration\Forum\ForumApi;
use App\Integration\Forum\ForumRequestException;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitOversightRepository;
use App\Repositories\UnitRepository;
use Illuminate\Support\Collection;

/**
 * Sends the counseling PMs (#565) from the acting user's own forum account, through the Forum seam (AD-3), and finds who
 * they go to: the trooper, the S1 1IC and 2IC and the trooper's battalion oversight officer (an LOR's copy), and the
 * commander who decides an appeal. A PM that cannot go out throws {@see CounselingPmException}, which the handlers let
 * through from inside their audited transaction, so the step that needed it does not happen.
 */
class CounselingMessenger
{
    public function __construct(
        private readonly ForumApi $forum,
        private readonly TrooperRepository $troopers,
        private readonly UnitRepository $units,
        private readonly UnitOversightRepository $oversights,
        private readonly UnitCommandMappingRepository $commands,
    ) {}

    /**
     * Send one PM to `$recipientIds` (forum user ids; duplicates and nobody are dropped). Nothing is sent to nobody.
     *
     * @param  array{title: string, message: string}  $pm
     * @param  list<int>  $recipientIds
     *
     * @throws CounselingPmException
     */
    public function send(?ForumPmGrant $grant, array $recipientIds, array $pm): void
    {
        $recipientIds = array_values(array_unique(array_filter($recipientIds, fn (int $id) => $id > 0)));
        if ($recipientIds === []) {
            return;
        }

        if ($grant === null || ! $grant->isLive()) {
            throw CounselingPmException::consentNeeded();
        }

        try {
            $this->forum->startConversation($grant->accessToken, $recipientIds, $pm['title'], $pm['message']);
        } catch (ForumRequestException $e) {
            throw CounselingPmException::refused($e->httpStatus, $e->forumCode, $e);
        }
    }

    /**
     * Fail before anything is written when a PM will be needed and cannot go: no live grant.
     *
     * @throws CounselingPmException
     */
    public function requireGrant(?ForumPmGrant $grant): void
    {
        if ($grant === null || ! $grant->isLive()) {
            throw CounselingPmException::consentNeeded();
        }
    }

    /**
     * The trooper's forum user id.
     *
     * @throws CounselingPmException when the roster has none for them
     */
    public function forumIdOf(Trooper $trooper): int
    {
        return $trooper->forum_user_id ?: throw CounselingPmException::noForumAccount((string) $trooper->username);
    }

    /** A portal user's forum user id, or 0 (dropped by {@see send()}) when unknown. */
    public function forumIdOfUser(?User $user): int
    {
        return (int) ($user?->forum_user_id ?? 0);
    }

    /**
     * Who an LOR is copied to for record processing: the serving S1 1IC and 2IC (`counseling.s1_billets`), and the
     * serving oversight officer of the trooper's battalion (or of the Reservists), the nearest one on its chain.
     *
     * @return list<int> forum user ids
     */
    public function recordKeepers(Trooper $trooper): array
    {
        $ids = $this->s1()->all();

        $section = $trooper->primary_section_id === null ? null : $this->units->find((int) $trooper->primary_section_id);
        if ($section !== null) {
            $chain = array_values(array_filter(array_map('intval', explode('/', (string) $section->path))));
            $oversight = $this->oversights->forUnits($chain)
                ->sortBy(fn ($row, int $unitId) => array_search($unitId, $chain, true))
                ->last();
            $officer = $oversight?->trooper;
            if ($officer !== null && ($officer->status?->isServing() ?? false)) {
                $ids[] = (int) $officer->forum_user_id;
            }
        }

        return array_values(array_filter($ids));
    }

    /**
     * The serving S1 1IC and 2IC's forum user ids.
     *
     * @return Collection<int, int>
     */
    public function s1(): Collection
    {
        return $this->troopers->servingWithBillets(array_values((array) config('counseling.s1_billets', [])))
            ->map(fn (Trooper $trooper) => (int) $trooper->forum_user_id)
            ->filter()
            ->values();
    }

    /** The serving commander of `$unitId` (its lead billet), or null. */
    public function commanderOf(?int $unitId): ?Trooper
    {
        if ($unitId === null) {
            return null;
        }

        $lead = $this->commands->commandersOf([$unitId])->get($unitId);

        return $lead !== null && ($lead->status ?? null) instanceof TrooperStatus && $lead->status->isServing() ? $lead : null;
    }

    /** `PFC Okafor.D`: rank and Last.F, as a PM greets and names a trooper. */
    public static function nameOf(?Trooper $trooper, string $fallback = ''): string
    {
        if ($trooper === null) {
            return $fallback;
        }

        return trim(($trooper->rank_short ?? '').' '.($trooper->username ?? $fallback));
    }
}

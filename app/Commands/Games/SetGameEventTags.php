<?php

namespace App\Commands\Games;

use App\Models\Game;
use App\Models\User;
use App\Repositories\GameRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Set the calendar title tags of a game (#751): the text in a 7Cav calendar event's title that marks it as the game's
 * (`[HLL]`, `SQUAD |`), so a section that plays the game lists the event on its roll call. Matched anywhere in the title,
 * ignoring case. Admin-only, gated by the controller (AD-6). Each tag is trimmed, and a blank one or one given twice
 * (in any case) is dropped. Saving the tags it already has records nothing; a change is one audited action (AD-16).
 */
class SetGameEventTags
{
    public function __construct(
        private readonly GameRepository $games,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @param  list<string>  $tags
     */
    public function handle(User $actor, Game $game, array $tags): Game
    {
        $tags = self::normalize($tags);

        if ($game->eventTags() === $tags) {
            return $game;
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'game.event_tags_set',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->games->setEventTags($game, $tags),
        );
    }

    /**
     * The tags as they are kept: trimmed, in the order given, without a blank one or a second of the same in any case.
     *
     * @param  list<string>  $tags
     * @return list<string>
     */
    public static function normalize(array $tags): array
    {
        $kept = [];
        $seen = [];

        foreach ($tags as $tag) {
            $tag = trim(preg_replace('/\s+/u', ' ', $tag) ?? $tag);
            $key = mb_strtolower($tag);

            if ($tag === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $kept[] = $tag;
        }

        return $kept;
    }
}

<?php

namespace App\Integration\Cav\Data;

use App\Integration\Cav\CavRequestException;

/**
 * A Cav position group (story 2.9) — a company (`A/1-7`), a command staff (`1-7 Command`) or a
 * holding group (`Retired`) — with the billets it lists. Which of those it is, is the importer's
 * business rule, not this seam's: the DTO carries the group as Cav publishes it.
 *
 * `$positions` is a plain array for the same reason as {@see RosterFetchResult}: a `readonly`
 * Collection would still be mutable through `push()`, an array is a value.
 */
readonly class CavPositionGroup
{
    /**
     * @param  list<CavPosition>  $positions
     */
    public function __construct(
        public int $id,
        public string $title,
        public int $displayOrder,
        public array $positions,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws CavRequestException when the group or any of its positions is unusable
     */
    public static function fromPayload(array $payload): self
    {
        $id = self::positiveInteger($payload['groupId'] ?? null)
            ?? throw CavRequestException::malformedPositionGroups('a group carried no usable `groupId`');

        $title = $payload['groupTitle'] ?? null;

        if (! is_string($title) || trim($title) === '') {
            throw CavRequestException::malformedPositionGroups("group {$id} carried no `groupTitle`");
        }

        $positions = $payload['positions'] ?? null;

        if (! is_array($positions) || ! array_is_list($positions)) {
            throw CavRequestException::malformedPositionGroups("group {$id} carried no `positions` list");
        }

        $parsed = array_map(function (mixed $position) use ($id) {
            if (! is_array($position)) {
                throw CavRequestException::malformedPositionGroups("group {$id} listed a position that was not an object");
            }

            return CavPosition::fromPayload($position);
        }, $positions);

        // Cav's own display order, so a caller creating nodes in list order gets them in the
        // order the roster shows them. `usort` is stable, so ties keep payload order.
        usort($parsed, fn (CavPosition $a, CavPosition $b) => $a->displayOrder <=> $b->displayOrder);

        return new self(
            id: $id,
            title: trim($title),
            displayOrder: self::positiveInteger($payload['groupDisplayOrder'] ?? null) ?? 0,
            positions: $parsed,
        );
    }

    /**
     * Cav sends ids as numeric strings and display orders as ints; accept either for both, so a
     * JSON-encoding drift between the two does not fail the import.
     *
     * @internal shared with {@see CavPosition}
     */
    public static function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $int = (int) $value;

            return $int > 0 ? $int : null;
        }

        return null;
    }
}

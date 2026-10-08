<?php

namespace App\Integration\Cav\Data;

use App\Integration\Cav\CavRequestException;

/**
 * One billet inside a Cav position group (story 2.9): its stable numeric id — the key
 * `unit_billet_mappings.cav_position_id` is keyed on (2.5) — and its free-text title, which
 * encodes the billet's place in the chain (`Section Leader 2/1/A/1-7`).
 */
readonly class CavPosition
{
    public function __construct(
        public int $id,
        public string $title,
        public int $displayOrder,
    ) {}

    /**
     * Strict on `id` and `title`, the two fields the importer keys on: a position without them
     * cannot be mapped or placed, and guessing would write a wrong mapping. `displayOrder` only
     * orders creation, so a missing one degrades to 0 rather than failing the whole fetch.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws CavRequestException when `positionId` or `positionTitle` is unusable
     */
    public static function fromPayload(array $payload): self
    {
        $id = CavPositionGroup::positiveInteger($payload['positionId'] ?? null)
            ?? throw CavRequestException::malformedPositionGroups('a position carried no usable `positionId`');

        $title = $payload['positionTitle'] ?? null;

        if (! is_string($title) || trim($title) === '') {
            throw CavRequestException::malformedPositionGroups("position {$id} carried no `positionTitle`");
        }

        return new self($id, trim($title), CavPositionGroup::positiveInteger($payload['positionDisplayOrder'] ?? null) ?? 0);
    }
}

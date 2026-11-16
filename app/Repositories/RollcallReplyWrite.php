<?php

namespace App\Repositories;

use App\Models\RollcallSignature;
use Carbon\CarbonImmutable;

/**
 * One trooper's latest reply on a week's roll call thread, as {@see RollcallReplyRepository::replace()} stores it: the
 * forum post, its link, when it was made, and the answer read from it (null when none was recognised).
 */
final readonly class RollcallReplyWrite
{
    public function __construct(
        public int $trooperId,
        public int $postId,
        public ?string $postUrl,
        public CarbonImmutable $postedAt,
        public ?RollcallSignature $answer,
    ) {}
}

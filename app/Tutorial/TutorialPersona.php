<?php

namespace App\Tutorial;

use App\Models\UnitType;

/**
 * Who the person plays in the tutorial, read from their real standing when they start it: whether they are an Admin,
 * the echelon they command (if any) and the HQ they sit on as staff (if any). Kept in the session as an array, so the
 * step list never reads real data again while the tutorial is on.
 */
final readonly class TutorialPersona
{
    public function __construct(
        public bool $admin,
        public ?UnitType $leads,
        public ?UnitType $hqStaff,
        public string $username,
        public int $forumUserId,
        public ?int $cavId,
        public string $rankShort,
        public string $rank,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'admin' => $this->admin,
            'leads' => $this->leads?->value,
            'hqStaff' => $this->hqStaff?->value,
            'username' => $this->username,
            'forumUserId' => $this->forumUserId,
            'cavId' => $this->cavId,
            'rankShort' => $this->rankShort,
            'rank' => $this->rank,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            admin: (bool) ($data['admin'] ?? false),
            leads: isset($data['leads']) ? UnitType::tryFrom((string) $data['leads']) : null,
            hqStaff: isset($data['hqStaff']) ? UnitType::tryFrom((string) $data['hqStaff']) : null,
            username: (string) ($data['username'] ?? 'Practice.U'),
            forumUserId: (int) ($data['forumUserId'] ?? 0),
            cavId: isset($data['cavId']) ? (int) $data['cavId'] : null,
            rankShort: (string) ($data['rankShort'] ?? 'SGT'),
            rank: (string) ($data['rank'] ?? 'Sergeant'),
        );
    }

    /** Whether the person runs roll call and sends a unit report in the tutorial. */
    public function leadsUnit(): bool
    {
        return $this->admin || $this->leads !== null;
    }
}

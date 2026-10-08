<?php

namespace App\Authorization;

use Illuminate\Support\Collection;

/**
 * A view-as target that passed this request's check against real authority (AD-9): the narrowed
 * scope every read filters through, plus what the shell's banner says about it.
 */
final readonly class ActiveViewAs
{
    /**
     * @param  Collection<int, int>  $scope  unit ids, always a subset of the viewer's real scope
     * @param  string  $label  who the preview is of: `SGT Vasquez.R`, or a unit's designation
     * @param  ?string  $designation  the unit the preview is anchored to, when there is one
     * @param  ?string  $unitName  that unit's name
     * @param  bool  $admin  whether the previewed view is an Admin's (a unit's leader never is)
     */
    public function __construct(
        public ViewAsTarget $target,
        public Collection $scope,
        public string $label,
        public ?string $designation,
        public ?string $unitName,
        public bool $admin,
    ) {}

    /**
     * The same preview with its scope cut down to `$ceiling` — how {@see ScopeResolver::evaluate()}
     * holds every preview inside the viewer's real authority (AD-9).
     *
     * @param  Collection<int, int>  $ceiling
     */
    public function narrowedTo(Collection $ceiling): self
    {
        return new self($this->target, $this->scope->intersect($ceiling)->values(), $this->label, $this->designation, $this->unitName, $this->admin);
    }

    /**
     * The shape the shell receives as the shared `viewAs` prop.
     *
     * @return array{kind: string, id: int, label: string, designation: ?string, unitName: ?string, admin: bool}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->target->kind->value,
            'id' => $this->target->id,
            'label' => $this->label,
            'designation' => $this->designation,
            'unitName' => $this->unitName,
            'admin' => $this->admin,
        ];
    }
}

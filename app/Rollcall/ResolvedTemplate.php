<?php

namespace App\Rollcall;

use App\Models\TemplateVersion;

/**
 * Which template a unit renders from, and why when it has none (Story 3.6, AD-17). The version id travels with it so a
 * render can record exactly which version it used.
 *
 * Both "no template" cases are explicit states, never a blank body or an opaque error, so the rollcall screen can say
 * what is missing: a company that has not authored a template yet, or a unit that sits above every company (the
 * regiment or a battalion), which has no template of its own to resolve.
 */
final readonly class ResolvedTemplate
{
    private function __construct(
        public TemplateResolution $state,
        public ?int $companyId,
        public ?TemplateVersion $version,
    ) {}

    public static function resolved(int $companyId, TemplateVersion $version): self
    {
        return new self(TemplateResolution::Resolved, $companyId, $version);
    }

    /** The owning company exists but has no active version of this kind. */
    public static function noActiveTemplate(int $companyId): self
    {
        return new self(TemplateResolution::NoActiveTemplate, $companyId, null);
    }

    /** No ancestor of the unit, and not the unit itself, is a company. */
    public static function noOwningCompany(): self
    {
        return new self(TemplateResolution::NoOwningCompany, null, null);
    }

    public function isResolved(): bool
    {
        return $this->state === TemplateResolution::Resolved;
    }

    /** The id of the version a render used, or null when none resolved. */
    public function versionId(): ?int
    {
        return $this->version?->id;
    }

    /**
     * The prop shape the rollcall screen reads. A render records `versionId` to say which version it used.
     *
     * @return array{state: string, companyId: ?int, versionId: ?int, version: ?int, body: ?string}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'companyId' => $this->companyId,
            'versionId' => $this->version?->id,
            'version' => $this->version?->version,
            'body' => $this->version?->body,
        ];
    }
}

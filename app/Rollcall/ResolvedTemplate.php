<?php

namespace App\Rollcall;

use App\Models\Template;
use App\Models\TemplateVersion;

/**
 * Which template a unit renders from, and why when it has none (Story 3.6, #375, AD-17). The template and version ids
 * travel with it so a render can record exactly which version it used, and `source` says why this one applies: the
 * section's pick (of its own template, a company one or the default), the company's active template, or the regiment
 * default.
 *
 * The "no template" cases are explicit states, never a blank body or an opaque error, so the rollcall screen can say
 * what is missing: a unit that sits above every company (the regiment or a battalion) has no company to ask, and a
 * company with nothing that applies falls back to the regiment default, so it shows "none" only if that is missing.
 */
final readonly class ResolvedTemplate
{
    private function __construct(
        public TemplateResolution $state,
        public ?int $companyId,
        public ?Template $template,
        public ?TemplateVersion $version,
        public ?TemplateSource $source,
    ) {}

    public static function resolved(int $companyId, Template $template, TemplateVersion $version, TemplateSource $source): self
    {
        return new self(TemplateResolution::Resolved, $companyId, $template, $version, $source);
    }

    /** The owning company exists but neither it nor the regiment has an active version of this kind. */
    public static function noActiveTemplate(int $companyId): self
    {
        return new self(TemplateResolution::NoActiveTemplate, $companyId, null, null, null);
    }

    /** No ancestor of the unit, and not the unit itself, is a company. */
    public static function noOwningCompany(): self
    {
        return new self(TemplateResolution::NoOwningCompany, null, null, null, null);
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
     * @return array{state: string, companyId: ?int, templateId: ?int, name: ?string, source: ?string, versionId: ?int, version: ?int, body: ?string}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'companyId' => $this->companyId,
            'templateId' => $this->template?->id,
            'name' => $this->template?->name,
            'source' => $this->source?->value,
            'versionId' => $this->version?->id,
            'version' => $this->version?->version,
            'body' => $this->version?->body,
        ];
    }
}

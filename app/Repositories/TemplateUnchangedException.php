<?php

namespace App\Repositories;

use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\UnitTemplateChoice;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Raised by {@see TemplateVersionRepository} from inside its locked section when a write would change nothing: the
 * body is the active version's already, the version is the active one already, a template is switched that way
 * already, or a unit's choice is that template already (or there is none to clear). Thrown rather than returned so it
 * rolls the surrounding audited transaction back, which is what keeps work that did not happen out of the audit log
 * (AD-16) and out of the version numbers. The Commands catch it and hand back `$subject`; it never reaches a caller.
 */
final class TemplateUnchangedException extends RuntimeException
{
    public function __construct(public readonly ?Model $subject, string $message = '')
    {
        parent::__construct($message !== '' ? $message : "Template version {$subject?->getKey()} is already the active one with this body.");
    }

    public static function forTemplate(Template $template): self
    {
        return new self($template, "Template {$template->id} is switched that way already.");
    }

    public static function forChoice(UnitTemplateChoice $choice): self
    {
        return new self($choice, "Unit {$choice->unit_id} has chosen template {$choice->template_id} already.");
    }

    public static function noChoice(): self
    {
        return new self(null, 'The unit has no template choice to clear.');
    }

    /** The version the write found unchanged; only for the version writes, which always carry one. */
    public function version(): TemplateVersion
    {
        assert($this->subject instanceof TemplateVersion);

        return $this->subject;
    }
}

<?php

namespace App\Rollcall;

/**
 * The outcome of resolving a unit's template (Story 3.6). Backed so the value a page receives and this type stay in
 * lockstep.
 */
enum TemplateResolution: string
{
    /** An active version was found for the unit's owning company. */
    case Resolved = 'resolved';

    /** The owning company has not authored (or has no active) template of this kind. */
    case NoActiveTemplate = 'no_active_template';

    /** The unit is not under any company, so there is no company to ask. */
    case NoOwningCompany = 'no_owning_company';
}

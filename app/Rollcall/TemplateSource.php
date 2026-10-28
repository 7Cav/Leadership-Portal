<?php

namespace App\Rollcall;

/**
 * Where a resolved template came from (#375, AD-17), in the order the resolver tries them. Backed so the value a page
 * receives and this type stay in lockstep.
 */
enum TemplateSource: string
{
    /** The section leader picked it from the templates the company switched on. */
    case Picked = 'picked';

    /** The company's active template, switched on for its sections. */
    case Company = 'company';

    /** The regiment-wide default: the company has nothing switched on that applies. */
    case RegimentDefault = 'default';
}

<?php

namespace App\Integration\Forum\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * The whole 7Cav forum calendar (the `nf_calendar` add-on) as iCalendar text, `GET {base}/events/export` (#751). It
 * is public: it needs no key or token, so none is sent. It lists the events still to come, a repeating one once, with
 * the date it first took place.
 */
class ExportCalendarRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/events/export';
    }
}

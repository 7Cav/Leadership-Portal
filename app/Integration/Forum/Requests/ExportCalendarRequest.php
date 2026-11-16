<?php

namespace App\Integration\Forum\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * The whole 7Cav forum calendar (the `nf_calendar` add-on) as iCalendar text, `GET {base}/events/export` (#751), or
 * one of its calendars, `GET {base}/calendar/{id}/export` (Operations / Battlefield 6). It is public: it needs no key
 * or token, so none is sent. It lists the events still to come, a repeating one once, with the date it first took
 * place. The whole calendar's export does not say which calendar an event is in; one calendar's export is how the sync
 * learns it.
 */
class ExportCalendarRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(public readonly ?int $calendarId = null) {}

    public function resolveEndpoint(): string
    {
        return $this->calendarId === null ? '/events/export' : '/calendar/'.$this->calendarId.'/export';
    }
}

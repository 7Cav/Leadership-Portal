<?php

namespace App\Http\Controllers;

use App\Kpi\AutoExcused;
use App\Loa\LoaCoverage;
use App\Models\UnitType;
use App\Repositories\UnitRepository;
use App\Rollcall\ResultImage;
use App\Rollcall\ResultRows;
use App\Rollcall\RollcallRoster;
use App\Rollcall\SectionFireteams;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The live roll call result images (#641): the Signed RC and the Attended SP of a section's week as a PNG, which the roll
 * call BBCode embeds by URL so the forum post shows the latest marks without being pasted again.
 *
 * Public by design: the forum fetches the image with no portal login, so the unit's secret `rollcall_image_token` in the
 * URL is the key. It shows only what the BBCode post already carries (name, rank, the two marks), and answers 404 for
 * anything it does not recognise, so a guess learns nothing. Read-only; the route skips the session middleware, so the
 * response sets no cookie and a cache may keep it.
 */
final class RollcallResultImageController extends Controller
{
    /** How long a browser or the forum's image cache may reuse an image before asking again. */
    private const MAX_AGE_SECONDS = 60;

    public function __construct(
        private readonly UnitRepository $units,
        private readonly RollcallRoster $roster,
        private readonly LoaCoverage $loaCoverage,
        private readonly AutoExcused $autoExcused,
        private readonly SectionFireteams $fireteams,
    ) {}

    public function show(Request $request, string $token, string $week, string $kind): Response
    {
        $section = $this->units->findByRollcallImageToken($token);
        abort_unless($section?->type === UnitType::Section && isset(ResultImage::KINDS[$kind]), 404);

        $weekStart = $this->weekStart($week);
        abort_if($weekStart === null || $weekStart->greaterThan(WeekOf::start(CarbonImmutable::now())), 404);

        $troopers = $this->roster->forWeek($section, $weekStart)->troopers;
        $ids = array_column($troopers, 'id');
        $covers = $this->loaCoverage->forTroopers($ids, $weekStart);
        $excused = $this->autoExcused->forTroopers($ids, $weekStart);

        $rows = ResultRows::for(array_map(fn (array $row) => [
            ...$row,
            'locked' => $covers[$row['id']]->isCovered(),
            'excused' => $excused[$row['id']] ?? null,
        ], $troopers), (bool) $this->fireteams->options($section, false, false)['show']);

        $title = ($section->callsign ?? $section->name).' | '.$this->forumDate($weekStart).' - '.$this->forumDate($weekStart->addWeek()->subMinute());

        $response = response()->make(ResultImage::render($kind, $title, $rows), 200, [
            'Content-Type' => 'image/png',
            // The security headers default to same-origin; this one is meant to be embedded by the forum.
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'X-Robots-Tag' => 'noindex',
            'Content-Disposition' => 'inline; filename="rollcall-'.$kind.'.png"',
        ]);
        $response->setPublic()->setMaxAge(self::MAX_AGE_SECONDS)->setEtag(md5($kind.$title.json_encode($rows)));
        $response->isNotModified($request);

        return $response;
    }

    private function weekStart(string $week): ?CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $week, 'UTC');

        return $date !== false && $date->toDateString() === $week ? WeekOf::start($date) : null;
    }

    /** `03Oct26`, as the forum post writes dates. */
    private function forumDate(CarbonImmutable $date): string
    {
        return $date->format('dMy');
    }
}

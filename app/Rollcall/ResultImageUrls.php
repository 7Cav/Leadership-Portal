<?php

namespace App\Rollcall;

use App\Models\Unit;
use Carbon\CarbonInterface;

/**
 * The addresses of a section's live roll call result images for one week (#641), which a template prints as
 * `{{rc_image}}` and `{{sp_image}}`. One place, so the roll call page and the template editor's preview of a real section
 * send the same URLs. The unit's secret token in each is what lets the forum load it without a login.
 */
final class ResultImageUrls
{
    /**
     * @return array{rc: string, sp: string}
     */
    public static function for(Unit $section, CarbonInterface $weekStart): array
    {
        $url = fn (string $kind) => route('rollcall.image', [
            'token' => $section->rollcall_image_token,
            'week' => $weekStart->toDateString(),
            'kind' => $kind,
        ]);

        return ['rc' => $url('rc'), 'sp' => $url('sp')];
    }
}

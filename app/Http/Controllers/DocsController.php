<?php

namespace App\Http\Controllers;

use App\Docs\DocsPages;
use App\Docs\GuideLibrary;
use App\Docs\ViewerAudience;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The in-portal guides (#409): docs home and one guide. It orchestrates only; which guides a viewer reads is the
 * {@see ViewerAudience} (from the ScopeResolver, so view-as applies) and what each page shows is {@see DocsPages}.
 *
 * The whole site answers 404 while `portal.docs` is off. A guide outside the viewer's audience answers 404 too, the
 * same as a slug that does not exist, so the answer never confirms that a guide for someone else is there.
 */
class DocsController extends Controller
{
    public function __construct(
        private readonly ViewerAudience $audience,
        private readonly DocsPages $pages,
        private readonly GuideLibrary $library,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless((bool) config('portal.docs'), 404);

        return Inertia::render('Docs/Index', $this->pages->home($this->audience->of($request->user())));
    }

    /**
     * The "?" help in the top bar (#411): the viewer's best guide for the page they were on (`?from=/rollcall`), or docs
     * home when none matches or the only match is a guide they may not read. The guide is told where they came from so
     * it can offer the way back. Only a path on this portal is passed on, never `//elsewhere`, and never one with a control
     * character or backslash, which a browser drops or reads as `/` and so could turn into `//elsewhere`.
     */
    public function help(Request $request): RedirectResponse
    {
        abort_unless((bool) config('portal.docs'), 404);

        $from = (string) $request->query('from', '');
        if (! str_starts_with($from, '/') || str_starts_with($from, '//') || preg_match('/[\x00-\x1F\x7F\\\\]/', $from) === 1) {
            return redirect('/docs');
        }

        $guide = $this->library->guideFor($this->audience->of($request->user()), $from);

        return redirect($guide === null ? '/docs' : $guide.'?'.http_build_query(['from' => strtok($from, '#')]));
    }

    public function show(Request $request, string $section, string $slug): Response
    {
        abort_unless((bool) config('portal.docs'), 404);

        $props = $this->pages->guide($this->audience->of($request->user()), $section, $slug);
        abort_if($props === null, 404);

        return Inertia::render('Docs/Show', $props);
    }
}

<?php

namespace App\Http\Controllers;

use App\Docs\DocsPages;
use App\Docs\ViewerAudience;
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
    ) {}

    public function index(Request $request): Response
    {
        abort_unless((bool) config('portal.docs'), 404);

        return Inertia::render('Docs/Index', $this->pages->home($this->audience->of($request->user())));
    }

    public function show(Request $request, string $section, string $slug): Response
    {
        abort_unless((bool) config('portal.docs'), 404);

        $props = $this->pages->guide($this->audience->of($request->user()), $section, $slug);
        abort_if($props === null, 404);

        return Inertia::render('Docs/Show', $props);
    }
}

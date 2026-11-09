<?php

namespace App\Http\Controllers;

use App\Authorization\ActAs;
use App\Authorization\ViewAs;
use App\Tutorial\PracticeWorld;
use App\Tutorial\TutorialPersona;
use App\Tutorial\TutorialPersonaResolver;
use App\Tutorial\TutorialSandbox;
use App\Tutorial\TutorialSession;
use App\Tutorial\TutorialSteps;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Start, start over and exit the hands-on tutorial. These run outside the sandbox (the sandbox middleware skips the
 * `tutorial.*` routes): they read the person's real standing, make or remove their practice copy, and fill it from
 * inside the sandbox. Entering is a session flag, like view as, so it is not in the audit log.
 */
class TutorialController extends Controller
{
    public function __construct(
        private readonly TutorialSandbox $sandbox,
        private readonly TutorialSession $tutorial,
        private readonly PracticeWorld $world,
        private readonly TutorialSteps $steps,
    ) {}

    public function store(Request $request, TutorialPersonaResolver $personas, ActAs $actAs, ViewAs $viewAs): RedirectResponse
    {
        $user = $request->user();

        if ($actAs->storedFor($user) !== null || $viewAs->targetFor($user) !== null) {
            return back()->with('notice', 'Stop View as or Act as before you start the tutorial.');
        }

        $persona = $personas->for($user);
        $from = PreviousPage::of($request);
        $this->fresh($user->id, $persona);
        $this->tutorial->start($user->id, $persona, $from !== null && ! str_starts_with($from, '/tutorial') ? $from : '/home');
        Inertia::clearHistory();

        return redirect($this->firstStep($user->id, $persona));
    }

    public function restart(Request $request): RedirectResponse
    {
        $user = $request->user();
        $persona = $this->tutorial->persona();

        if (! $this->tutorial->activeFor($user->id) || $persona === null) {
            return redirect()->route('docs.index');
        }

        $this->fresh($user->id, $persona);
        Inertia::clearHistory();

        return redirect($this->firstStep($user->id, $persona))->with('notice', 'The tutorial starts over with fresh practice data.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $return = $this->tutorial->stop() ?? '/home';
        // Leaves no practice data behind: the next start makes a fresh copy.
        $this->sandbox->discard($request->user()->id);
        Inertia::clearHistory();

        return redirect($return);
    }

    private function fresh(int $userId, TutorialPersona $persona): void
    {
        $this->sandbox->prepare($userId);
        $this->sandbox->run($userId, fn () => $this->world->build($userId, $persona));
    }

    private function firstStep(int $userId, TutorialPersona $persona): string
    {
        return $this->sandbox->run($userId, fn () => $this->steps->describe($userId, $persona)[0]['href'] ?? '/home');
    }
}

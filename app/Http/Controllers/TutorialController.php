<?php

namespace App\Http\Controllers;

use App\Authorization\ActAs;
use App\Authorization\ViewAs;
use App\Commands\Tutorial\RecordTutorialUse;
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
 * inside the sandbox. Starting, starting over and leaving are noted in the real audit log against the person
 * (RecordTutorialUse); what they do inside stays in their practice copy.
 */
class TutorialController extends Controller
{
    public function __construct(
        private readonly TutorialSandbox $sandbox,
        private readonly TutorialSession $tutorial,
        private readonly PracticeWorld $world,
        private readonly TutorialSteps $steps,
        private readonly RecordTutorialUse $record,
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
        $this->record->handle($user, RecordTutorialUse::STARTED);
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
        $this->record->handle($user, RecordTutorialUse::RESTARTED);
        Inertia::clearHistory();

        return redirect($this->firstStep($user->id, $persona))->with('notice', 'The tutorial starts over with fresh practice data.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->tutorial->stop();
        // Leaves no practice data behind: the next start makes a fresh copy.
        $this->sandbox->discard($request->user()->id);
        $this->record->handle($request->user(), RecordTutorialUse::EXITED);
        Inertia::clearHistory();

        // Always the guides home, where the tutorial is started: the page it started from may be a practice page.
        return redirect('/docs');
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

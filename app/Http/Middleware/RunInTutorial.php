<?php

namespace App\Http\Middleware;

use App\Authorization\ServingSession;
use App\Repositories\PageVisitRepository;
use App\Repositories\TutorialPracticeRepository;
use App\Tutorial\TutorialSandbox;
use App\Tutorial\TutorialSession;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Runs the request in the tutorial while the person's session has it on: the whole request, from route model binding
 * on, reads and writes their own practice copy, with every outbound path faked ({@see TutorialSandbox}). Sits in the
 * middleware priority list right before SubstituteBindings, so nothing after it reads real data.
 *
 * Before entering it checks, on the real data, that the person may still be signed in at all, so leaving Cav ends the
 * tutorial as it ends any session. View as and act as cannot run in the tutorial: their routes are refused, and their
 * session keys are dropped when the tutorial starts. The tutorial's own start, restart and exit routes run outside the
 * sandbox, as they make and remove the practice copy.
 */
class RunInTutorial
{
    public const ATTRIBUTE = 'tutorial';

    /** Route names that are not used in the tutorial. */
    private const REFUSED = ['view-as.', 'act-as.'];

    public function __construct(
        private readonly TutorialSandbox $sandbox,
        private readonly TutorialSession $tutorial,
        private readonly TutorialPracticeRepository $practice,
        private readonly ServingSession $serving,
        private readonly PageVisitRepository $visits,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $name = (string) $request->route()?->getName();

        if ($user === null || ! $this->tutorial->activeFor((int) $user->getAuthIdentifier()) || str_starts_with($name, 'tutorial.')) {
            return $next($request);
        }

        $userId = (int) $user->getAuthIdentifier();
        if ($this->serving->mustEnd($user) || ! $this->sandbox->exists($userId)) {
            $this->tutorial->stop();

            return $next($request);
        }

        foreach (self::REFUSED as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $message = 'Exit the tutorial to use View as or Act as.';

                return $request->expectsJson() && ! $request->header('X-Inertia')
                    ? response()->json(['message' => $message], 409)
                    : back()->with('notice', $message);
            }
        }

        $this->sandbox->enter($userId);
        $copy = $this->practice->user($userId);
        if ($copy === null) {
            $this->sandbox->leave();
            $this->tutorial->stop();

            return $next($request);
        }

        Auth::setUser($copy);
        $request->setUserResolver(fn () => $copy);
        $request->attributes->set(self::ATTRIBUTE, true);

        return $next($request);
    }

    /**
     * A tab switch (the roster's Strength tab) is a partial reload, which the activity log leaves out. In the
     * tutorial it is kept in the practice copy, so the coach card can tick it. Still inside the sandbox here.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! $request->attributes->get(self::ATTRIBUTE) || ! $this->sandbox->entered() || ! $request->isMethod('GET')
            || ! $request->hasHeader('X-Inertia-Partial-Component') || $response->getStatusCode() !== 200
            // The coach card's own refresh is not something the person did.
            || $request->header('X-Inertia-Partial-Data') === 'tutorialMode') {
            return;
        }

        try {
            $this->visits->record((int) $this->sandbox->userId(), RecordPageVisit::address($request), null, null, CarbonImmutable::now('UTC'));
        } catch (Throwable $e) {
            report($e);
        }
    }
}

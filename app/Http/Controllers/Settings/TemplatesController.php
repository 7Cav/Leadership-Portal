<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\ScopeResolver;
use App\Commands\Templates\ActivateTemplateVersion;
use App\Commands\Templates\AuthorTemplateVersion;
use App\Commands\Templates\InvalidTemplateException;
use App\Http\Controllers\Controller;
use App\Loa\LoaCoverage;
use App\Models\ArtifactType;
use App\Models\TemplateVersion;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitRepository;
use App\Rollcall\RollcallRoster;
use App\Rollcall\SectionFireteams;
use App\Rollcall\TemplateSyntax;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rollcall template authoring (Story 3.5, AD-17). An Admin edits a company's BBCode template in one place and the
 * edit becomes a new immutable version that every section under the company renders from. Reads the repositories
 * directly, as the other settings screens do, and writes through the audited {@see AuthorTemplateVersion} and
 * {@see ActivateTemplateVersion} Commands (AD-16).
 *
 * Viewing is open to any authenticated user, matching org-structure and staff grants. Only the Admin flag gates
 * writing, and it is checked here on every write, whatever the page shows (AD-6). The rollcall screen itself never
 * edits a template (3.11): authoring lives here.
 */
class TemplatesController extends Controller
{
    private const TYPE = ArtifactType::RollcallBbcode;

    public function __construct(
        private readonly TemplateVersionRepository $versions,
        private readonly UnitRepository $units,
        private readonly RollcallRoster $roster,
    ) {}

    public function index(Request $request, ScopeResolver $scope): Response
    {
        $companies = $this->units->tree()
            ->filter(fn (Unit $unit) => $unit->type === UnitType::Company)
            ->values();

        $selected = $companies->firstWhere('id', $request->integer('company')) ?? $companies->first();
        $history = $selected === null ? collect() : $this->versions->history($selected->id, self::TYPE);
        $activeId = $selected === null ? null : $this->versions->activeFor($selected->id, self::TYPE)?->id;
        $active = $history->first(fn (TemplateVersion $version) => $version->id === $activeId);

        return Inertia::render('Settings/Templates', [
            'artifactType' => ['value' => self::TYPE->value, 'label' => self::TYPE->label()],
            'companies' => $companies->map(fn (Unit $unit) => [
                'id' => $unit->id,
                'name' => $unit->name,
                'callsign' => $unit->callsign,
            ])->all(),
            'company' => $selected?->id,
            'active' => $active === null ? null : $this->version($active),
            'history' => $history
                ->map(fn (TemplateVersion $version) => $this->version($version) + ['isActive' => $version->id === $activeId])
                ->values()
                ->all(),
            'maxBodyLength' => AuthorTemplateVersion::MAX_BODY_LENGTH,
            // A template to start from (Story 3.10). The screen lists the codes a template may use from the browser's own
            // parser (`lib/bbcode.js`), which the shared cases hold to `TemplateSyntax`.
            'starter' => TemplateSyntax::STARTER,
            // Follows a view-as preview: the page then shows its authoring controls disabled, as read-only for the
            // previewed leader. The write gates below read the real flag.
            'isAdmin' => $scope->actsAsAdmin($request->user()),
            // The sections the preview can be filled from instead of the sample: those under the company that the viewer
            // could open on the rollcall page. Their rows load only when one is picked ({@see previewSection()}).
            'previewSections' => $selected === null ? [] : $this->roster->options($this->sectionsUnder($selected, $request->user(), $scope)),
        ]);
    }

    /**
     * One section's rollcall for the current week, for the preview to fill the template with: the same section, week and
     * trooper rows the rollcall page reads ({@see RollcallRoster}), so the preview shows the post that section would copy.
     * Gated as the rollcall page is (AD-8): a section outside the viewer's scope is a 403. It must also sit under the
     * company being edited, since that is the template it renders from; anything else is a 404. The section's fireteam
     * display goes along in the rollcall page's shape ({@see SectionFireteams::options()}), since it sets the post's
     * order and its FTL lines; the preview changes nothing, so it says the viewer may edit neither.
     */
    public function previewSection(Request $request, int $unit, ScopeResolver $scope, LoaCoverage $loa, SectionFireteams $fireteams): JsonResponse
    {
        // Answered as JSON, like the rollcall page's own background calls: the page reads it with fetch, not a visit.
        $validator = Validator::make($request->query(), ['company' => ['required', 'integer']]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        abort_unless($scope->resolve($request->user())->contains($unit), 403, 'This section is outside your scope.');

        $company = $this->units->find((int) $request->query('company'));
        abort_unless($company?->type === UnitType::Company, 404);

        $sections = $this->sectionsUnder($company, $request->user(), $scope);
        $section = $sections->firstWhere('id', $unit);
        abort_if($section === null, 404);

        $week = WeekOf::start(CarbonImmutable::now());
        $troopers = $this->roster->forWeek($section, $week)->troopers;
        // The post prints a trooper whose leave covers the week as LOA, so the rows carry their leave as the rollcall
        // page's do (`LoaCover::toArray()` when it covers them or needs review). The page's attendance strip is not
        // part of the post, so it is left out here.
        $covers = $loa->forTroopers(array_column($troopers, 'id'), $week);

        return response()->json([
            'section' => $this->roster->section($section, $sections),
            'week' => [...$this->roster->week($week, $week), 'beforeFloor' => WeekOf::isBeforeFloor($week)],
            'troopers' => array_map(fn (array $row) => [
                ...$row,
                'loa' => $covers[$row['id']]->suppressesAtRisk() ? $covers[$row['id']]->toArray() : null,
            ], $troopers),
            'fireteams' => $fireteams->options($section, canEdit: false, canEditPolicy: false),
        ]);
    }

    /**
     * Save an edit as a new active version. The Admin gate is enforced here, server-side (AD-6).
     */
    public function store(Request $request, AuthorTemplateVersion $command): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403, 'Only an Admin may edit a template.');

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:units,id'],
            'body' => ['required', 'string', 'max:'.AuthorTemplateVersion::MAX_BODY_LENGTH],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $company = $this->units->find($data['company_id']);

        try {
            $command->handle($request->user(), $company, self::TYPE, $data['body'], $data['reason']);
        } catch (InvalidTemplateException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return redirect()->route('settings.templates', ['company' => $company->id]);
    }

    /**
     * Make an existing version the active one again. Admin-only, enforced here (AD-6), and before the version
     * is looked up, so a non-Admin gets the same 403 whether or not the id exists.
     */
    public function activate(Request $request, int $version, ActivateTemplateVersion $command): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403, 'Only an Admin may activate a template version.');

        $version = $this->versions->find($version) ?? abort(404);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $command->handle($request->user(), $version, $data['reason']);

        return redirect()->route('settings.templates', ['company' => $version->company_id]);
    }

    /**
     * The sections under `$company` in the viewer's scope (view-as included), each with its platoon loaded.
     *
     * @return Collection<int, Unit>
     */
    private function sectionsUnder(Unit $company, User $user, ScopeResolver $scope): Collection
    {
        return $this->units->sectionsAmong($scope->resolve($user)->intersect($this->units->subtreeIds($company)));
    }

    /**
     * @return array{id: int, version: int, body: string, createdAt: string, createdBy: ?string}
     */
    private function version(TemplateVersion $version): array
    {
        return [
            'id' => $version->id,
            'version' => $version->version,
            'body' => $version->body,
            'createdAt' => $version->created_at->toIso8601String(),
            'createdBy' => $version->relationLoaded('author') ? $version->author?->username : null,
        ];
    }
}

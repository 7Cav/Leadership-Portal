<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\ScopeResolver;
use App\Commands\Templates\ActivateTemplateVersion;
use App\Commands\Templates\AuthorTemplateVersion;
use App\Commands\Templates\CreateTemplate;
use App\Commands\Templates\InvalidTemplateException;
use App\Commands\Templates\SetCompanyTemplate;
use App\Commands\Templates\SwitchTemplate;
use App\Commands\Templates\TemplateAuthority;
use App\Http\Controllers\Controller;
use App\Loa\LoaCoverage;
use App\Models\ArtifactType;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitDesignationOrder;
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
 * Rollcall template authoring (Story 3.5, #375, AD-17). A company keeps named templates, each a line of immutable
 * versions with one active, and the regiment-wide default always shows beside them. Reads the repositories directly,
 * as the other settings screens do, and writes through the audited `Commands/Templates` Commands (AD-16).
 *
 * An Admin sees every company. Anyone else sees only the companies their read scope reaches into (AD-8), so a leader
 * reads their own company's templates and no one else's; asking for another company is a 403. Who may write is the
 * Commands' {@see TemplateAuthority}: a company's command (CO, XO, 1SG, the command above it, an acting lead of the
 * company) for that company's templates, and only an Admin for the regiment default. The page gets the same answer to
 * show or hide its controls, but the Commands decide. The rollcall screen never edits a template (3.11): it only picks
 * one.
 */
class TemplatesController extends Controller
{
    private const TYPE = ArtifactType::RollcallBbcode;

    public function __construct(
        private readonly TemplateVersionRepository $versions,
        private readonly UnitRepository $units,
        private readonly RollcallRoster $roster,
        private readonly UnitCommandMappingRepository $commands,
        private readonly TemplateAuthority $authority,
    ) {}

    public function index(Request $request, ScopeResolver $scope): Response
    {
        $user = $request->user();
        $tree = $this->units->tree();
        $companies = $this->visibleCompanies($user, $scope, $tree);
        $selected = $companies->firstWhere('id', $request->integer('company'));

        // Anyone but an Admin gets the same 403 for a company outside their scope and for an id that is no company at
        // all, so the answer says nothing about which ids exist. An Admin asking for a non-company lands on their own.
        abort_if($selected === null && $request->filled('company') && ! $scope->actsAsAdmin($user), 403, 'This company is outside your scope.');

        $selected ??= $this->ownCompany($user, $scope, $companies, $tree) ?? $companies->first();

        $default = $this->versions->regimentDefault(self::TYPE);
        $own = $selected === null ? collect() : $this->versions->templatesOf($selected->id, self::TYPE);
        $templates = collect([$default])->filter()->merge($own)->values();
        $companyActiveId = $selected === null ? null : $this->versions->choiceFor($selected->id, self::TYPE)?->template_id;

        // A template of another company is no answer here: it is a 404 whether it exists or not.
        $template = $request->filled('template') ? $templates->firstWhere('id', $request->integer('template')) : null;
        abort_if($template === null && $request->filled('template'), 404);
        $template ??= $templates->firstWhere('id', $companyActiveId) ?? $own->first() ?? $default;

        $history = $template === null ? collect() : $this->versions->historyOf($template);
        $active = $history->first(fn (TemplateVersion $version) => $version->id === $template->active_version_id);
        $managesCompany = $selected !== null && $this->authority->managesCompany($user, $selected);

        return Inertia::render('Settings/Templates', [
            'artifactType' => ['value' => self::TYPE->value, 'label' => self::TYPE->label()],
            'companies' => $companies->map(fn (Unit $unit) => [
                'id' => $unit->id,
                'name' => $unit->name,
                'callsign' => $unit->callsign,
            ])->all(),
            'company' => $selected?->id,
            // The regiment default first, then the company's own, oldest first.
            'templates' => $templates->map(fn (Template $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'isRegimentDefault' => $item->isRegimentDefault(),
                'enabled' => $item->isRegimentDefault() || $item->enabled,
                'isCompanyActive' => $item->id === $companyActiveId,
                'version' => $item->activeVersion?->version,
            ])->all(),
            'template' => $template?->id,
            'active' => $active === null ? null : $this->version($active),
            'history' => $history
                ->map(fn (TemplateVersion $version) => $this->version($version) + ['isActive' => $version->id === $template->active_version_id])
                ->values()
                ->all(),
            'maxBodyLength' => AuthorTemplateVersion::MAX_BODY_LENGTH,
            'maxNameLength' => CreateTemplate::MAX_NAME_LENGTH,
            // A template to start from (Story 3.10). The screen lists the codes a template may use from the browser's own
            // parser (`lib/bbcode.js`), which the shared cases hold to `TemplateSyntax`.
            'starter' => TemplateSyntax::STARTER,
            // Follows a view-as preview, for the Admin badge.
            'isAdmin' => $scope->actsAsAdmin($user),
            // Whether the viewer may edit and activate the open template, and add and switch the company's (the
            // Commands' own rule). During a view-as preview the page shows these controls disabled, as the middleware
            // refuses every write then anyway.
            'canManage' => $template !== null && $this->authority->manages($user, $template),
            'canManageCompany' => $managesCompany,
            // The sections the preview can be filled from instead of the sample: those under the company that the viewer
            // could open on the rollcall page, in designation order. The page fills the preview with the first one (the
            // first section of the first platoon); a section's rows load only when it is shown ({@see previewSection()}).
            'previewSections' => $selected === null ? [] : $this->roster->options(UnitDesignationOrder::sort($this->sectionsUnder($selected, $user, $scope), $tree)),
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
     * Add a named template to a company, with its first version. The company's command or an Admin, checked by the
     * Command; anyone else gets the same 403 for a company that exists and one that does not.
     */
    public function create(Request $request, CreateTemplate $command): RedirectResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:'.CreateTemplate::MAX_NAME_LENGTH],
            'body' => ['required', 'string', 'max:'.AuthorTemplateVersion::MAX_BODY_LENGTH],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $company = $this->units->find($data['company_id']) ?? $this->missing($request->user(), 'company_id');

        try {
            $template = $command->handle($request->user(), $company, self::TYPE, $data['name'], $data['body'], $data['reason']);
        } catch (InvalidTemplateException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return redirect()->route('settings.templates', ['company' => $company->id, 'template' => $template->id]);
    }

    /**
     * Save an edit of a template as its new active version. Who may is the Command's rule.
     */
    public function store(Request $request, AuthorTemplateVersion $command): RedirectResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'integer'],
            'body' => ['required', 'string', 'max:'.AuthorTemplateVersion::MAX_BODY_LENGTH],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $template = $this->versions->findTemplate($data['template_id']) ?? $this->missing($request->user(), 'template_id');

        try {
            $command->handle($request->user(), $template, $data['body'], $data['reason']);
        } catch (InvalidTemplateException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return $this->back($template);
    }

    /**
     * Make an existing version its template's active one again. A non-Admin gets the same 403 whether or not the id
     * exists; the Command refuses a version of a template they may not manage.
     */
    public function activate(Request $request, int $version, ActivateTemplateVersion $command): RedirectResponse
    {
        $version = $this->versions->find($version) ?? $this->missing($request->user());

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $command->handle($request->user(), $version, $data['reason']);

        return $this->back($this->versions->findTemplate($version->template_id));
    }

    /**
     * Switch a company template on or off for the company's sections.
     */
    public function switch(Request $request, int $template, SwitchTemplate $command): RedirectResponse
    {
        $template = $this->versions->findTemplate($template) ?? $this->missing($request->user());

        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        try {
            $command->handle($request->user(), $template, (bool) $data['enabled']);
        } catch (InvalidTemplateException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return $this->back($template);
    }

    /**
     * Make a company template the company's active one, the one its sections use unless they pick another.
     */
    public function companyActive(Request $request, int $template, SetCompanyTemplate $command): RedirectResponse
    {
        $template = $this->versions->findTemplate($template) ?? $this->missing($request->user());

        try {
            $command->handle($request->user(), $template);
        } catch (InvalidTemplateException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return $this->back($template);
    }

    /**
     * A record that is not there: an Admin is told so (a 404, or a form error on `$field`), anyone else gets the 403 a
     * record they may not touch gets, so a missing id and a forbidden one look alike.
     */
    private function missing(User $user, ?string $field = null): never
    {
        abort_unless($user->is_admin, 403, 'You may not change this template.');

        if ($field !== null) {
            throw ValidationException::withMessages([$field => 'That record no longer exists.']);
        }

        abort(404);
    }

    private function back(?Template $template): RedirectResponse
    {
        return redirect()->route('settings.templates', array_filter([
            'company' => $template?->company_id,
            'template' => $template?->id,
        ]));
    }

    /**
     * The companies the viewer may read the templates of, in designation order ({@see UnitDesignationOrder}): every
     * company for an acting Admin, otherwise those with at least one unit of the viewer's read scope (view-as included)
     * at or under them.
     *
     * @param  Collection<int, Unit>  $tree
     * @return Collection<int, Unit>
     */
    private function visibleCompanies(User $user, ScopeResolver $scope, Collection $tree): Collection
    {
        $companies = UnitDesignationOrder::sort($tree->filter(fn (Unit $unit) => $unit->type === UnitType::Company)->values(), $tree);

        if ($scope->actsAsAdmin($user)) {
            return $companies;
        }

        $readable = $scope->resolve($user)->flip();
        $paths = $tree->filter(fn (Unit $unit) => $readable->has($unit->id))->pluck('path');

        return $companies
            ->filter(fn (Unit $company) => $paths->contains(fn (string $path) => str_starts_with($path, $company->path)))
            ->values();
    }

    /**
     * The company the page opens on when none is asked for: the one the viewer's command billet sits in (their company,
     * or the company above their platoon or section), else the first company their scope (view-as included) reaches
     * into. Null when neither applies, which leaves the first company.
     *
     * @param  Collection<int, Unit>  $companies  in designation order
     * @param  Collection<int, Unit>  $tree
     */
    private function ownCompany(User $user, ScopeResolver $scope, Collection $companies, Collection $tree): ?Unit
    {
        $readable = $scope->resolve($user)->flip();
        $commanded = $this->commands->commandedUnitFor($user);

        if ($commanded !== null && $readable->has($commanded->id)) {
            $own = $companies->first(fn (Unit $company) => str_starts_with($commanded->path, $company->path));

            if ($own !== null) {
                return $own;
            }
        }

        $paths = $tree->filter(fn (Unit $unit) => $readable->has($unit->id))->pluck('path');

        return $companies->first(fn (Unit $company) => $paths->contains(fn (string $path) => str_starts_with($path, $company->path)));
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

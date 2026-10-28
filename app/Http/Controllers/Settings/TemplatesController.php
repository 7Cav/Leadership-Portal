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
 * versions with one active, and the regiment-wide default always shows beside them. A section may keep templates of its
 * own the same way: the page opens one of the company's sections (`?section=`) to list and edit those instead. Reads
 * the repositories directly, as the other settings screens do, and writes through the audited `Commands/Templates`
 * Commands (AD-16).
 *
 * An Admin sees every company. Anyone else sees only the companies their read scope reaches into (AD-8), and only the
 * sections of those in their scope, so a leader reads their own company's templates and no one else's; asking for
 * another company or section is a 403. Who may write is the Commands' {@see TemplateAuthority}: a company's command
 * (CO, XO, 1SG, the command above it, an acting lead of the company) for that company's templates, a section's command
 * (SL, ASL and everyone above) for the section's own, and only an Admin for the regiment default. The page gets the
 * same answer to show or hide its controls, but the Commands decide. The rollcall screen never edits a template
 * (3.11): it only picks one.
 */
class TemplatesController extends Controller
{
    private const TYPE = ArtifactType::RollcallBbcode;

    /** What an editor reads when a template form is refused, matching the command's own refusals. */
    private const TEMPLATE_MESSAGES = [
        'name.required' => 'A template needs a name.',
        'name.max' => 'A template name can be at most :max characters.',
        'body.required' => 'A template needs some text.',
        'body.max' => 'A template can be at most :max characters.',
        'reason.required' => 'Give a reason for the record.',
        'reason.max' => 'Keep the reason to :max characters.',
    ];

    public function __construct(
        private readonly TemplateVersionRepository $versions,
        private readonly UnitRepository $units,
        private readonly RollcallRoster $roster,
        private readonly UnitCommandMappingRepository $commands,
        private readonly TemplateAuthority $authority,
        private readonly ScopeResolver $scopeResolver,
    ) {}

    public function index(Request $request, ScopeResolver $scope): Response
    {
        $user = $request->user();
        $tree = $this->units->tree();
        $companies = $this->visibleCompanies($user, $scope, $tree);
        [$selected, $section] = $request->filled('section')
            ? $this->askedSection($request->integer('section'), $user, $scope, $companies)
            : [$companies->firstWhere('id', $request->integer('company')), null];

        // Anyone but an Admin gets the same 403 for a company outside their scope and for an id that is no company at
        // all, so the answer says nothing about which ids exist. An Admin asking for a non-company lands on their own.
        abort_if($selected === null && $request->filled('company') && ! $scope->actsAsAdmin($user), 403, 'This company is outside your scope.');

        $selected ??= $this->ownCompany($user, $scope, $companies, $tree) ?? $companies->first();
        $sections = $selected === null ? collect() : UnitDesignationOrder::sort($this->sectionsUnder($selected, $user, $scope), $tree);

        // With a section open the list is that section's own templates; otherwise the regiment default and the company's.
        $default = $this->versions->regimentDefault(self::TYPE);
        $own = match (true) {
            $section !== null => $this->versions->templatesOf($section->id, self::TYPE),
            $selected !== null => $this->versions->templatesOf($selected->id, self::TYPE),
            default => collect(),
        };
        $templates = $section !== null ? $own->values() : collect([$default])->filter()->merge($own)->values();
        $companyActiveId = $selected === null || $section !== null ? null : $this->versions->choiceFor($selected->id, self::TYPE)?->template_id;

        // A template of another company or section is no answer here: it is a 404 whether it exists or not.
        $template = $request->filled('template') ? $templates->firstWhere('id', $request->integer('template')) : null;
        abort_if($template === null && $request->filled('template'), 404);
        $template ??= $section !== null ? $own->first() : ($templates->firstWhere('id', $companyActiveId) ?? $own->first() ?? $default);

        $history = $template === null ? collect() : $this->versions->historyOf($template);
        $active = $history->first(fn (TemplateVersion $version) => $version->id === $template->active_version_id);

        return Inertia::render('Settings/Templates', [
            'artifactType' => ['value' => self::TYPE->value, 'label' => self::TYPE->label()],
            'companies' => $companies->map(fn (Unit $unit) => [
                'id' => $unit->id,
                'name' => $unit->name,
                'callsign' => $unit->callsign,
            ])->all(),
            'company' => $selected?->id,
            // The company's sections the viewer may read, in designation order, to open one's own templates instead,
            // and the one open (null for the company's).
            'sections' => $this->roster->options($sections),
            'section' => $section?->id,
            // The regiment default first, then the company's own, oldest first; or the open section's own.
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
            // Whether the viewer may edit and activate the open template, add and switch the company's, and add the
            // open section's (the Commands' own rule). During a view-as preview the page shows these controls
            // disabled, as the middleware refuses every write then anyway.
            'canManage' => $template !== null && $this->authority->manages($user, $template),
            'canManageCompany' => $section === null && $selected !== null && $this->authority->managesUnit($user, $selected),
            'canManageSection' => $section !== null && $this->authority->managesUnit($user, $section),
            // The sections the preview can be filled from instead of the sample: those under the company that the viewer
            // could open on the rollcall page, in designation order, or only the open section, the one its own templates
            // render for. The page fills the preview with the first one (the first section of the first platoon); a
            // section's rows load only when it is shown ({@see previewSection()}).
            'previewSections' => $section === null ? $this->roster->options($sections) : [$this->roster->section($section, $sections)],
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
     * Add a named template to a company, or to a section (`section_id`) as its own, with its first version. The unit's
     * command or an Admin, checked by the Command; anyone else gets the same 403 for a unit that exists and one that
     * does not.
     */
    public function create(Request $request, CreateTemplate $command): RedirectResponse
    {
        $data = $request->validate([
            'company_id' => ['required_without:section_id', 'nullable', 'integer'],
            'section_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:'.CreateTemplate::MAX_NAME_LENGTH],
            'body' => ['required', 'string', 'max:'.AuthorTemplateVersion::MAX_BODY_LENGTH],
            'reason' => ['required', 'string', 'max:1000'],
        ], self::TEMPLATE_MESSAGES);

        $field = isset($data['section_id']) ? 'section_id' : 'company_id';
        $owner = $this->units->find($data[$field]) ?? $this->missing($request->user(), $field);

        try {
            $template = $command->handle($request->user(), $owner, self::TYPE, $data['name'], $data['body'], $data['reason']);
        } catch (InvalidTemplateException $e) {
            // The Command names the unit's field as the company's; a section's form sent it as `section_id`.
            throw ValidationException::withMessages([($e->field === 'company_id' ? $field : $e->field) => $e->getMessage()]);
        }

        return $this->back($template);
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
        ], self::TEMPLATE_MESSAGES);

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
        ], self::TEMPLATE_MESSAGES);

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
        abort_unless($this->scopeResolver->isAdmin($user), 403, 'You may not change this template.');

        if ($field !== null) {
            throw ValidationException::withMessages([$field => 'That record no longer exists. Reload the page.']);
        }

        abort(404);
    }

    /** Back to the template on the page: its company's list, or its section's for a section's own. */
    private function back(?Template $template): RedirectResponse
    {
        $owner = $template?->isSectionTemplate() ? ['section' => $template->section_id] : ['company' => $template?->company_id];

        return redirect()->route('settings.templates', array_filter([...$owner, 'template' => $template?->id]));
    }

    /**
     * The company and section the page opens for `?section=`: a section under one of the viewer's companies and in
     * their read scope (view-as included), with its company. Anyone but an Admin gets the same 403 for a section outside
     * their scope and an id that is no section, as for a company; an Admin is told a missing one is not there.
     *
     * @param  Collection<int, Unit>  $companies  the companies the viewer may read
     * @return array{0: Unit, 1: Unit}
     */
    private function askedSection(int $id, User $user, ScopeResolver $scope, Collection $companies): array
    {
        $asked = $this->units->find($id);
        $company = $asked?->type === UnitType::Section ? $this->units->owningCompany($asked) : null;
        $company = $company === null ? null : $companies->firstWhere('id', $company->id);
        $section = $company === null ? null : $this->sectionsUnder($company, $user, $scope)->firstWhere('id', $id);

        if ($section === null) {
            abort_unless($scope->actsAsAdmin($user), 403, 'This section is outside your scope.');
            abort(404);
        }

        return [$company, $section];
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
            'createdBy' => $version->relationLoaded('author') ? $this->author($version) : null,
        ];
    }

    /**
     * Who wrote the version: "Hale.S for Pike.S" when an Admin acting as Pike.S did, "Hale.S acting as Company
     * Commander A/1-7" when an Admin holding the billet did (#379), else the author alone. Null for the seeded default.
     */
    private function author(TemplateVersion $version): ?string
    {
        $name = $version->author?->username;

        return match (true) {
            $name === null => null,
            $version->authorAdmin !== null => "{$version->authorAdmin->username} for {$name}",
            $version->authorUnit !== null => "{$name} acting as {$version->authorUnit->billet()}",
            default => $name,
        };
    }
}

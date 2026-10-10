<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\ScopeResolver;
use App\Commands\Templates\ActivateTemplateVersion;
use App\Commands\Templates\AuthorTemplateVersion;
use App\Commands\Templates\InvalidTemplateException;
use App\Http\Controllers\Controller;
use App\Models\ArtifactType;
use App\Models\TemplateVersion;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            // Follows a view-as preview, so authoring controls hide as they would for the previewed leader. The
            // write gates below read the real flag.
            'isAdmin' => $scope->actsAsAdmin($request->user()),
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
     * Make an existing version the active one again. Admin-only, enforced here (AD-6).
     */
    public function activate(Request $request, TemplateVersion $version, ActivateTemplateVersion $command): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403, 'Only an Admin may activate a template version.');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $command->handle($request->user(), $version, $data['reason']);

        return redirect()->route('settings.templates', ['company' => $version->company_id]);
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

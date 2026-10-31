<?php

namespace App\Http\Controllers;

use App\Authorization\ActAs;
use App\Authorization\ChangelogAccess;
use App\Authorization\ScopeResolver;
use App\Commands\Changelog\ChangelogEntries;
use App\Commands\Changelog\ChangelogSettings;
use App\Docs\SafeMarkdown;
use App\Models\ChangelogEntry;
use App\Repositories\ChangelogRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The changelog at /changelog: open to everyone signed in, entries grouped by date, newest date first. Only the changelog editors, signed in as
 * themselves, write it ({@see ChangelogAccess}); everyone else gets a 403 on every write, checked here before anything
 * else and again in {@see ChangelogEntries}. Every write is audited (AD-16) and on the audited-writes throttle.
 *
 * Opening the page records when the viewer last saw it, for the sidebar's "new" dot. Not during a view-as preview, nor
 * while someone acts as the viewer: neither is the person themselves reading it.
 *
 * Technical entries are sent only to a viewer who may see them ({@see ChangelogAccess::seesTechnical()}): an editor
 * always, everyone else only while an Admin has made them available. The filter is here, not in the page.
 */
class ChangelogController extends Controller
{
    public function __construct(
        private readonly ChangelogRepository $changelog,
        private readonly ChangelogAccess $access,
        private readonly ScopeResolver $scope,
    ) {}

    public function index(Request $request, SafeMarkdown $markdown): Response
    {
        $user = $request->user();
        $editor = $this->access->mayEdit($user);
        $technicalEnabled = $this->changelog->technicalEnabled();
        $mayManage = $this->access->mayManageSettings($user);

        if ($request->attributes->get(ActAs::ADMIN_ATTRIBUTE) === null && $this->scope->activeViewAs($user) === null) {
            $this->changelog->markSeen($user->id, now());
        }

        return Inertia::render('Changelog', [
            // `[{ date, entries: [{ id, body, major, technical, detailsHtml, details }] }]`, newest date first, each date's
            // entries in the order they were written. Technical entries only for a viewer who may see them.
            'days' => $this->changelog->all($this->access->seesTechnical($user))
                ->groupBy(fn (ChangelogEntry $entry) => $entry->date->toDateString())
                ->map(fn ($entries, string $date) => [
                    'date' => $date,
                    'entries' => $entries->sortBy('id')->map(fn (ChangelogEntry $entry) => [
                        'id' => $entry->id,
                        'body' => $entry->body,
                        'major' => (bool) $entry->major,
                        'technical' => (bool) $entry->technical,
                        'detailsHtml' => $entry->details === null ? null : $markdown->toHtml($entry->details),
                        // The Markdown source only to an editor, for the edit form.
                        'details' => $editor ? $entry->details : null,
                    ])->values()->all(),
                ])->values()->all(),
            'isEditor' => $editor,
            // Whether a non-editor gets the "Show technical and security items" toggle. Editors always see them.
            'technicalToggle' => ! $editor && $technicalEnabled,
            // The Admin setting, sent only to someone who may change it.
            'technicalSetting' => $mayManage ? ['enabled' => $technicalEnabled] : null,
        ]);
    }

    public function store(Request $request, ChangelogEntries $command): RedirectResponse
    {
        $this->authorizeEditor($request);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'entries' => ['required', 'array', 'min:1', 'max:'.ChangelogEntry::MAX_BATCH],
            'entries.*.body' => ['required', 'string', 'max:'.ChangelogEntry::MAX_BODY_LENGTH],
            'entries.*.details' => ['nullable', 'string', 'max:'.ChangelogEntry::MAX_DETAILS_LENGTH],
            'entries.*.major' => ['sometimes', 'boolean'],
            'entries.*.technical' => ['sometimes', 'boolean'],
        ]);

        try {
            $command->add($request->user(), $data['date'], array_map(
                fn (array $item) => [
                    'body' => $item['body'],
                    'details' => $item['details'] ?? null,
                    'major' => (bool) ($item['major'] ?? false),
                    'technical' => (bool) ($item['technical'] ?? false),
                ],
                $data['entries'],
            ));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['entries' => $e->getMessage()]);
        }

        return redirect()->route('changelog.index');
    }

    public function update(Request $request, int $entry, ChangelogEntries $command): RedirectResponse
    {
        $this->authorizeEditor($request);
        $entry = $this->changelog->find($entry) ?? abort(404);
        $data = $request->validate($this->rules());

        try {
            $command->update($request->user(), $entry, $data['date'], $data['body'], $data['details'] ?? null, (bool) ($data['major'] ?? false), (bool) ($data['technical'] ?? false));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['body' => $e->getMessage()]);
        }

        return redirect()->route('changelog.index');
    }

    public function destroy(Request $request, int $entry, ChangelogEntries $command): RedirectResponse
    {
        $this->authorizeEditor($request);
        $entry = $this->changelog->find($entry) ?? abort(404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $command->delete($request->user(), $entry, $data['reason']);

        return redirect()->route('changelog.index');
    }

    /** The bulk import's preview: the entries the paste holds, which would be skipped, and any errors. Writes nothing. */
    public function preview(Request $request, ChangelogEntries $command): JsonResponse
    {
        $this->authorizeEditor($request);
        $data = $request->validate(['markdown' => ['required', 'string', 'max:500000']]);

        return response()->json($command->preview($data['markdown']));
    }

    public function import(Request $request, ChangelogEntries $command): RedirectResponse
    {
        $this->authorizeEditor($request);
        $data = $request->validate([
            'markdown' => ['required', 'string', 'max:500000'],
            'skip_duplicates' => ['required', 'boolean'],
        ]);

        try {
            $count = $command->import($request->user(), $data['markdown'], (bool) $data['skip_duplicates']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['markdown' => $e->getMessage()]);
        }

        return redirect()->route('changelog.index')->with('notice', $count === 1 ? '1 entry imported' : "{$count} entries imported");
    }

    public function dismiss(Request $request, ChangelogEntries $command): RedirectResponse
    {
        $this->authorizeEditor($request);
        $command->dismissOutdated($request->user());

        return back();
    }

    /** The Admin setting: whether technical entries are available to everyone. */
    public function settings(Request $request, ChangelogSettings $command): RedirectResponse
    {
        if (! $this->access->mayManageSettings($request->user())) {
            abort(403, 'Only an Admin, signed in as themselves, may change the changelog settings.');
        }
        $data = $request->validate(['technical_enabled' => ['required', 'boolean']]);

        $command->setTechnicalEnabled($request->user(), (bool) $data['technical_enabled']);

        return redirect()->route('changelog.index');
    }

    private function authorizeEditor(Request $request): void
    {
        if (! $this->access->mayEdit($request->user())) {
            abort(403, 'Only the changelog editors may change the changelog, signed in as themselves.');
        }
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'body' => ['required', 'string', 'max:'.ChangelogEntry::MAX_BODY_LENGTH],
            'details' => ['nullable', 'string', 'max:'.ChangelogEntry::MAX_DETAILS_LENGTH],
            'major' => ['sometimes', 'boolean'],
            'technical' => ['sometimes', 'boolean'],
        ];
    }
}
